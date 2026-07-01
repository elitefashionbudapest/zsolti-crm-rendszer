# Gmail OAuth-integráció — Implementációs terv

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Egy iroda a saját Gmail-fiókját OAuth2-vel bekötheti, és a beérkező levelek a Gmail API-n (HTTPS/443) töltődnek a postaládába, megkerülve a tárhely tiltott 993-as portját.

**Architecture:** Per-iroda OAuth2 authorization-code folyam raw Guzzle HTTP-vel (nincs google/apiclient). A `google_client_id/secret/refresh_token` a meglévő `office_settings` kulcsokon (titkosítva). Egy `MailboxSyncDispatcher` dönt futásidőben Gmail API vs. meglévő IMAP között; a levelek a változatlan `incoming_emails` táblába kerülnek.

**Tech Stack:** PHP 8.3, Slim 4, PHP-DI (autowire), Guzzle 7, Defuse (Encryption), PHPUnit (in-memory sqlite + Guzzle MockHandler), Twig.

**Spec:** `docs/superpowers/specs/2026-07-01-gmail-oauth-design.md`

---

## Fájlszerkezet

**Új fájlok:**
- `src/Support/SignedState.php` — HMAC-aláírt, lejáró `state` token (CSRF + officeId a callbackhez). Egy felelősség: aláír/ellenőriz.
- `src/Gmail/GmailAuthException.php` — dobható kivétel token-hibákra.
- `src/Gmail/GmailOAuthService.php` — OAuth2 folyam: auth-URL, code→token, access-token frissítés, leválasztás.
- `src/Gmail/GmailApiSyncService.php` — INBOX-behúzás a Gmail API-val + törzs-parser.
- `src/Mail/MailboxSyncDispatcher.php` — Gmail vs IMAP diszpécser.
- `tests/SignedStateTest.php`, `tests/GmailOAuthServiceTest.php`, `tests/GmailApiSyncServiceTest.php`, `tests/MailboxSyncDispatcherTest.php`

**Módosított fájlok:**
- `config/container.php` — `SignedState` factory.
- `config/routes/admin/settings.php` — 3 új útvonal.
- `src/Http/Controllers/Admin/SettingsController.php` — `gmailConnect/gmailCallback/gmailDisconnect` + `gmail_email` megjelenítés.
- `src/Http/Controllers/Admin/InboxController.php` — `ImapSyncService` → `MailboxSyncDispatcher`.
- `src/Console/ImapSyncCommand.php` — `ImapSyncService` → `MailboxSyncDispatcher`.
- `templates/admin/settings/index.twig` — a „Google" fül átalakítása Gmail-csatlakoztatássá.
- `.env.example` — `APP_URL` megjegyzés (a redirect URI ebből áll össze).

---

## Task 1: SignedState (HMAC-aláírt, lejáró token)

**Files:**
- Create: `src/Support/SignedState.php`
- Test: `tests/SignedStateTest.php`

- [ ] **Step 1: Írd meg a bukó tesztet**

```php
<?php
declare(strict_types=1);
namespace Tests;
use App\Support\SignedState;
use PHPUnit\Framework\TestCase;

final class SignedStateTest extends TestCase
{
    private SignedState $s;
    protected function setUp(): void { $this->s = new SignedState('teszt-titkos-kulcs'); }

    public function testRoundTripReturnsPayload(): void
    {
        $token = $this->s->sign(['office' => 7], 600);
        $data = $this->s->verify($token);
        self::assertNotNull($data);
        self::assertSame(7, $data['office']);
    }

    public function testTamperedTokenRejected(): void
    {
        $token = $this->s->sign(['office' => 7], 600);
        self::assertNull($this->s->verify($token . 'x'));
    }

    public function testExpiredTokenRejected(): void
    {
        $token = $this->s->sign(['office' => 7], -1); // már lejárt
        self::assertNull($this->s->verify($token));
    }

    public function testWrongKeyRejected(): void
    {
        $token = $this->s->sign(['office' => 7], 600);
        self::assertNull((new SignedState('masik-kulcs'))->verify($token));
    }
}
```

- [ ] **Step 2: Futtasd, hogy bukjon**

Run: `vendor/bin/phpunit tests/SignedStateTest.php`
Expected: FAIL — „Class App\Support\SignedState not found".

- [ ] **Step 3: Minimál implementáció**

```php
<?php
declare(strict_types=1);
namespace App\Support;

/**
 * HMAC-aláírt, lejáró állapot-token (pl. OAuth `state`). A payload base64url-ben
 * utazik, az aláírás az APP_KEY-jel készül. Nem titkosít, csak hitelesít.
 */
final class SignedState
{
    public function __construct(private string $key) {}

    /** @param array<string,mixed> $data */
    public function sign(array $data, int $ttlSeconds = 600): string
    {
        $data['exp'] = time() + $ttlSeconds;
        $payload = $this->b64(json_encode($data, JSON_UNESCAPED_UNICODE) ?: '{}');
        return $payload . '.' . $this->mac($payload);
    }

    /** @return array<string,mixed>|null */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) { return null; }
        [$payload, $sig] = $parts;
        if (!hash_equals($this->mac($payload), $sig)) { return null; }
        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($json === false) { return null; }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['exp']) || (int) $data['exp'] < time()) { return null; }
        return $data;
    }

    private function mac(string $payload): string
    {
        return $this->b64(hash_hmac('sha256', $payload, $this->key, true));
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
```

- [ ] **Step 4: Futtasd, hogy átmenjen**

Run: `vendor/bin/phpunit tests/SignedStateTest.php`
Expected: PASS (4 teszt).

- [ ] **Step 5: Commit**

```bash
git add src/Support/SignedState.php tests/SignedStateTest.php
git commit -m "feat: SignedState — HMAC-aláírt lejáró state token"
```

---

## Task 2: GmailOAuthService (OAuth2 folyam)

**Files:**
- Create: `src/Gmail/GmailAuthException.php`, `src/Gmail/GmailOAuthService.php`
- Test: `tests/GmailOAuthServiceTest.php`

A `SettingsService`-hez a teszt in-memory sqlite `office_settings` táblát ad, valós `Encryption`-nel (`Encryption::generateKey()`). A Google HTTP-hívások Guzzle `MockHandler`-rel mockolva.

- [ ] **Step 1: Írd meg a bukó tesztet**

```php
<?php
declare(strict_types=1);
namespace Tests;

use App\Gmail\GmailAuthException;
use App\Gmail\GmailOAuthService;
use App\Settings\SettingsService;
use App\Support\Encryption;
use App\Support\SignedState;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PDO;
use PHPUnit\Framework\TestCase;

final class GmailOAuthServiceTest extends TestCase
{
    private PDO $pdo;
    private SettingsService $settings;
    private SignedState $state;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('CREATE TABLE office_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT, office_id INTEGER, `key` TEXT,
            value_encrypted TEXT, created_at TEXT, updated_at TEXT)');
        $this->settings = new SettingsService($this->pdo, new Encryption(Encryption::generateKey()));
        $this->settings->set(1, 'google_client_id', 'CID.apps.googleusercontent.com');
        $this->settings->set(1, 'google_client_secret', 'SECRET');
        $this->state = new SignedState('kulcs');
    }

    private function service(array $responses): GmailOAuthService
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $http = new Client(['handler' => $stack]);
        return new GmailOAuthService($this->settings, $this->state, $http);
    }

    public function testAuthUrlContainsScopeAndState(): void
    {
        $url = $this->service([])->authUrl(1, 'https://app.hu/cb');
        self::assertStringContainsString('scope=https%3A%2F%2Fwww.googleapis.com%2Fauth%2Fgmail.readonly', $url);
        self::assertStringContainsString('access_type=offline', $url);
        self::assertStringContainsString('state=', $url);
        self::assertStringContainsString('client_id=CID', $url);
    }

    public function testHandleCallbackStoresRefreshTokenAndEmail(): void
    {
        $stateToken = $this->state->sign(['office' => 1], 600);
        $svc = $this->service([
            new Response(200, [], json_encode(['refresh_token' => 'RT', 'access_token' => 'AT'])),
            new Response(200, [], json_encode(['emailAddress' => 'iroda@gmail.com'])),
        ]);
        $result = $svc->handleCallback('CODE', $stateToken, 'https://app.hu/cb');
        self::assertSame('iroda@gmail.com', $result['email']);
        self::assertSame('RT', $this->settings->get(1, 'google_refresh_token'));
        self::assertSame('iroda@gmail.com', $this->settings->get(1, 'gmail_email'));
    }

    public function testHandleCallbackRejectsBadState(): void
    {
        $this->expectException(GmailAuthException::class);
        $this->service([])->handleCallback('CODE', 'hamis.token', 'https://app.hu/cb');
    }

    public function testAccessTokenUsesRefreshToken(): void
    {
        $this->settings->set(1, 'google_refresh_token', 'RT');
        $svc = $this->service([new Response(200, [], json_encode(['access_token' => 'AT2']))]);
        self::assertSame('AT2', $svc->accessToken(1));
    }

    public function testAccessTokenInvalidGrantDisconnects(): void
    {
        $this->settings->set(1, 'google_refresh_token', 'RT');
        $svc = $this->service([new Response(400, [], json_encode(['error' => 'invalid_grant']))]);
        try {
            $svc->accessToken(1);
            self::fail('GmailAuthException várt');
        } catch (GmailAuthException) {
            self::assertNull($this->settings->get(1, 'google_refresh_token'));
        }
    }
}
```

- [ ] **Step 2: Futtasd, hogy bukjon**

Run: `vendor/bin/phpunit tests/GmailOAuthServiceTest.php`
Expected: FAIL — „Class App\Gmail\GmailOAuthService not found".

- [ ] **Step 3: Implementáció — kivétel**

`src/Gmail/GmailAuthException.php`:
```php
<?php
declare(strict_types=1);
namespace App\Gmail;

final class GmailAuthException extends \RuntimeException {}
```

- [ ] **Step 4: Implementáció — GmailOAuthService**

`src/Gmail/GmailOAuthService.php`:
```php
<?php
declare(strict_types=1);
namespace App\Gmail;

use App\Settings\SettingsService;
use App\Support\SignedState;
use GuzzleHttp\Client;
use Throwable;

/**
 * Per-iroda Gmail OAuth2 (authorization code). A client_id/secret és a
 * refresh_token az office_settings-ben (utóbbi titkosítva). Raw Guzzle.
 * (Nem `final`: a tesztek anonim osztállyal stubolják az accessToken()-t.)
 */
class GmailOAuthService
{
    private const SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';
    private const AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN = 'https://oauth2.googleapis.com/token';
    private const PROFILE = 'https://gmail.googleapis.com/gmail/v1/users/me/profile';

    private Client $http;

    public function __construct(
        private SettingsService $settings,
        private SignedState $state,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client(['timeout' => 30]);
    }

    public function isConfigured(int $officeId): bool
    {
        return ((string) $this->settings->get($officeId, 'google_client_id', '')) !== ''
            && ((string) $this->settings->get($officeId, 'google_client_secret', '')) !== '';
    }

    public function isConnected(int $officeId): bool
    {
        return ((string) $this->settings->get($officeId, 'google_refresh_token', '')) !== '';
    }

    public function connectedEmail(int $officeId): ?string
    {
        $e = (string) $this->settings->get($officeId, 'gmail_email', '');
        return $e !== '' ? $e : null;
    }

    public function authUrl(int $officeId, string $redirectUri): string
    {
        $params = [
            'client_id' => (string) $this->settings->get($officeId, 'google_client_id', ''),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $this->state->sign(['office' => $officeId], 600),
        ];
        return self::AUTH . '?' . http_build_query($params);
    }

    /** @return array{email:string} */
    public function handleCallback(string $code, string $stateToken, string $redirectUri): array
    {
        $data = $this->state->verify($stateToken);
        if ($data === null || !isset($data['office'])) {
            throw new GmailAuthException('Érvénytelen vagy lejárt kérés.');
        }
        $officeId = (int) $data['office'];

        try {
            $res = $this->http->post(self::TOKEN, ['form_params' => [
                'code' => $code,
                'client_id' => (string) $this->settings->get($officeId, 'google_client_id', ''),
                'client_secret' => (string) $this->settings->get($officeId, 'google_client_secret', ''),
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ]]);
            $token = json_decode((string) $res->getBody(), true) ?: [];
        } catch (Throwable $e) {
            throw new GmailAuthException('Token-csere sikertelen: ' . $e->getMessage(), 0, $e);
        }

        $refresh = (string) ($token['refresh_token'] ?? '');
        $access = (string) ($token['access_token'] ?? '');
        if ($refresh === '') {
            throw new GmailAuthException('A Google nem adott refresh_tokent (prompt=consent kell).');
        }
        $this->settings->set($officeId, 'google_refresh_token', $refresh);

        $email = '';
        try {
            $p = $this->http->get(self::PROFILE, ['headers' => ['Authorization' => 'Bearer ' . $access]]);
            $email = (string) ((json_decode((string) $p->getBody(), true) ?: [])['emailAddress'] ?? '');
        } catch (Throwable) {
            // A profil nem kritikus; a token már mentve.
        }
        $this->settings->set($officeId, 'gmail_email', $email);
        $this->settings->set($officeId, 'gmail_connected_at', date('Y-m-d H:i:s'));

        return ['email' => $email];
    }

    public function accessToken(int $officeId): string
    {
        $refresh = (string) $this->settings->get($officeId, 'google_refresh_token', '');
        if ($refresh === '') {
            throw new GmailAuthException('Nincs Gmail-kapcsolat.');
        }
        try {
            $res = $this->http->post(self::TOKEN, ['http_errors' => false, 'form_params' => [
                'client_id' => (string) $this->settings->get($officeId, 'google_client_id', ''),
                'client_secret' => (string) $this->settings->get($officeId, 'google_client_secret', ''),
                'refresh_token' => $refresh,
                'grant_type' => 'refresh_token',
            ]]);
            $body = json_decode((string) $res->getBody(), true) ?: [];
        } catch (Throwable $e) {
            throw new GmailAuthException('Token-frissítés sikertelen: ' . $e->getMessage(), 0, $e);
        }

        if (($body['error'] ?? '') === 'invalid_grant') {
            $this->disconnect($officeId);
            throw new GmailAuthException('A Gmail-kapcsolat lejárt vagy visszavonták, csatlakoztasd újra.');
        }
        $access = (string) ($body['access_token'] ?? '');
        if ($access === '') {
            throw new GmailAuthException('Nem sikerült access tokent kapni.');
        }
        return $access;
    }

    public function disconnect(int $officeId): void
    {
        $this->settings->set($officeId, 'google_refresh_token', null);
        $this->settings->set($officeId, 'google_access_token', null);
        $this->settings->set($officeId, 'gmail_email', null);
        $this->settings->set($officeId, 'gmail_connected_at', null);
    }
}
```

- [ ] **Step 5: Futtasd, hogy átmenjen**

Run: `vendor/bin/phpunit tests/GmailOAuthServiceTest.php`
Expected: PASS (5 teszt).

- [ ] **Step 6: Commit**

```bash
git add src/Gmail/GmailAuthException.php src/Gmail/GmailOAuthService.php tests/GmailOAuthServiceTest.php
git commit -m "feat: GmailOAuthService — per-iroda OAuth2 (auth-url, token, refresh)"
```

---

## Task 3: GmailApiSyncService (INBOX behúzás + törzs-parser)

**Files:**
- Create: `src/Gmail/GmailApiSyncService.php`
- Test: `tests/GmailApiSyncServiceTest.php`

Az `incoming_emails` séma megegyezik a meglévővel. A parser a Gmail `payload`-fát járja be.

- [ ] **Step 1: Írd meg a bukó tesztet**

```php
<?php
declare(strict_types=1);
namespace Tests;

use App\Gmail\GmailApiSyncService;
use App\Gmail\GmailOAuthService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PDO;
use PHPUnit\Framework\TestCase;

final class GmailApiSyncServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('CREATE TABLE incoming_emails (
            id INTEGER PRIMARY KEY AUTOINCREMENT, office_id INTEGER, message_uid TEXT,
            from_email TEXT, subject TEXT, body TEXT, received_at TEXT,
            created_at TEXT, updated_at TEXT)');
    }

    private function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /** Egy GmailOAuthService, ami fix access tokent ad (nincs HTTP). */
    private function oauthStub(): GmailOAuthService
    {
        return new class extends GmailOAuthService {
            public function __construct() {}
            public function accessToken(int $officeId): string { return 'AT'; }
        };
    }

    public function testSyncStoresPlainTextMessage(): void
    {
        $list = ['messages' => [['id' => 'm1']]];
        $msg = ['payload' => [
            'headers' => [
                ['name' => 'From', 'value' => 'Feladó <ugyfel@example.com>'],
                ['name' => 'Subject', 'value' => 'Ajánlatkérés'],
                ['name' => 'Message-ID', 'value' => '<abc@mail>'],
                ['name' => 'Date', 'value' => 'Tue, 01 Jul 2026 10:00:00 +0200'],
            ],
            'mimeType' => 'text/plain',
            'body' => ['data' => $this->b64url('Szia! Kérek egy ajánlatot. Üdv: Á')],
        ]];
        $svc = $this->makeService([
            new Response(200, [], json_encode($list)),
            new Response(200, [], json_encode($msg)),
        ]);

        $result = $svc->syncOffice(1);

        self::assertNull($result['error']);
        self::assertSame(1, $result['count']);
        $row = $this->pdo->query('SELECT * FROM incoming_emails')->fetch();
        self::assertSame('ugyfel@example.com', $row['from_email']);
        self::assertSame('Ajánlatkérés', $row['subject']);
        self::assertStringContainsString('ajánlatot', $row['body']);
        self::assertSame('<abc@mail>', $row['message_uid']);
    }

    public function testSyncPrefersPlainInMultipart(): void
    {
        $list = ['messages' => [['id' => 'm2']]];
        $msg = ['payload' => [
            'headers' => [['name' => 'Message-ID', 'value' => '<m2@x>']],
            'mimeType' => 'multipart/alternative',
            'parts' => [
                ['mimeType' => 'text/plain', 'body' => ['data' => $this->b64url('SIMA szöveg')]],
                ['mimeType' => 'text/html', 'body' => ['data' => $this->b64url('<b>HTML</b>')]],
            ],
        ]];
        $svc = $this->makeService([
            new Response(200, [], json_encode($list)),
            new Response(200, [], json_encode($msg)),
        ]);

        $svc->syncOffice(1);

        $row = $this->pdo->query('SELECT body FROM incoming_emails')->fetch();
        self::assertStringContainsString('SIMA szöveg', $row['body']);
    }

    public function testSyncSkipsDuplicateMessageId(): void
    {
        $this->pdo->exec("INSERT INTO incoming_emails (office_id, message_uid) VALUES (1, '<dup@x>')");
        $list = ['messages' => [['id' => 'm3']]];
        $msg = ['payload' => ['headers' => [['name' => 'Message-ID', 'value' => '<dup@x>']], 'mimeType' => 'text/plain', 'body' => ['data' => $this->b64url('x')]]];
        $svc = $this->makeService([
            new Response(200, [], json_encode($list)),
            new Response(200, [], json_encode($msg)),
        ]);

        $result = $svc->syncOffice(1);

        self::assertSame(0, $result['count']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM incoming_emails')->fetchColumn());
    }

    private function makeService(array $responses): GmailApiSyncService
    {
        $http = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
        return new GmailApiSyncService($this->pdo, $this->oauthStub(), $http);
    }
}
```

- [ ] **Step 2: Futtasd, hogy bukjon**

Run: `vendor/bin/phpunit tests/GmailApiSyncServiceTest.php`
Expected: FAIL — „Class App\Gmail\GmailApiSyncService not found".

- [ ] **Step 3: Implementáció**

`src/Gmail/GmailApiSyncService.php`:
```php
<?php
declare(strict_types=1);
namespace App\Gmail;

use GuzzleHttp\Client;
use PDO;
use Throwable;

/**
 * Beérkező (INBOX) levelek behúzása a Gmail API-val (HTTPS/443) az
 * incoming_emails táblába. Ugyanaz a {count,error} alak, mint az IMAP-nál.
 * (Nem `final`: a diszpécser-teszt anonim osztállyal stubolja a syncOffice()-t.)
 */
class GmailApiSyncService
{
    private const BASE = 'https://gmail.googleapis.com/gmail/v1/users/me';

    private Client $http;

    public function __construct(
        private PDO $pdo,
        private GmailOAuthService $oauth,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client(['timeout' => 60]);
    }

    /** @return array{count:int, error:?string} */
    public function syncOffice(int $officeId, int $limit = 25): array
    {
        try {
            $token = $this->oauth->accessToken($officeId);
            $headers = ['Authorization' => 'Bearer ' . $token];

            $listRes = $this->http->get(self::BASE . '/messages', [
                'headers' => $headers,
                'query' => ['labelIds' => 'INBOX', 'q' => 'newer_than:30d', 'maxResults' => $limit],
            ]);
            $list = json_decode((string) $listRes->getBody(), true) ?: [];
            $ids = array_map(static fn ($m) => (string) $m['id'], $list['messages'] ?? []);

            $stored = 0;
            foreach ($ids as $id) {
                $msgRes = $this->http->get(self::BASE . '/messages/' . $id, [
                    'headers' => $headers,
                    'query' => ['format' => 'full'],
                ]);
                $msg = json_decode((string) $msgRes->getBody(), true) ?: [];
                $payload = $msg['payload'] ?? [];

                $uid = $this->header($payload, 'Message-ID') ?: (string) ($msg['id'] ?? '');
                if ($uid !== '' && $this->exists($officeId, $uid)) { continue; }

                $from = $this->extractEmail($this->header($payload, 'From'));
                $subject = $this->header($payload, 'Subject');
                $body = $this->extractBody($payload);
                $received = $this->parseDate($this->header($payload, 'Date'));

                $this->store($officeId, $uid, $from, $subject, mb_substr($body, 0, 5000), $received);
                $stored++;
            }
            return ['count' => $stored, 'error' => null];
        } catch (GmailAuthException $e) {
            return ['count' => 0, 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            return ['count' => 0, 'error' => $e->getMessage()];
        }
    }

    /** @param array<string,mixed> $payload */
    private function header(array $payload, string $name): string
    {
        foreach ($payload['headers'] ?? [] as $h) {
            if (strcasecmp((string) ($h['name'] ?? ''), $name) === 0) {
                return (string) ($h['value'] ?? '');
            }
        }
        return '';
    }

    /** A payload-fa bejárása: elsőbbség text/plain-nek, fallback text/html→szöveg. */
    private function extractBody(array $payload): string
    {
        $plain = $this->collect($payload, 'text/plain');
        if ($plain !== '') { return $plain; }
        $html = $this->collect($payload, 'text/html');
        return $html !== '' ? trim(html_entity_decode(strip_tags($html))) : '';
    }

    private function collect(array $node, string $mime): string
    {
        $out = '';
        if (($node['mimeType'] ?? '') === $mime && !empty($node['body']['data'])) {
            $out .= $this->b64urlDecode((string) $node['body']['data']);
        }
        foreach ($node['parts'] ?? [] as $part) {
            $out .= $this->collect($part, $mime);
        }
        return $out;
    }

    private function b64urlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), false);
    }

    private function extractEmail(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $m)) { return trim($m[1]); }
        return trim($from);
    }

    private function parseDate(string $date): string
    {
        $ts = $date !== '' ? strtotime($date) : false;
        return date('Y-m-d H:i:s', $ts !== false ? $ts : time());
    }

    private function exists(int $officeId, string $uid): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM incoming_emails WHERE office_id = :o AND message_uid = :u LIMIT 1');
        $stmt->execute(['o' => $officeId, 'u' => $uid]);
        return $stmt->fetchColumn() !== false;
    }

    private function store(int $officeId, string $uid, string $from, string $subject, string $body, string $receivedAt): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO incoming_emails (office_id, message_uid, from_email, subject, body, received_at, created_at, updated_at)
             VALUES (:o, :u, :f, :s, :b, :r, :c, :up)'
        );
        $stmt->execute([
            'o' => $officeId, 'u' => $uid !== '' ? $uid : null, 'f' => $from,
            's' => mb_substr($subject, 0, 255), 'b' => $body, 'r' => $receivedAt, 'c' => $now, 'up' => $now,
        ]);
    }
}
```

- [ ] **Step 4: Futtasd, hogy átmenjen**

Run: `vendor/bin/phpunit tests/GmailApiSyncServiceTest.php`
Expected: PASS (3 teszt).

- [ ] **Step 5: Commit**

```bash
git add src/Gmail/GmailApiSyncService.php tests/GmailApiSyncServiceTest.php
git commit -m "feat: GmailApiSyncService — INBOX behúzás + törzs-parser (Gmail API)"
```

---

## Task 4: MailboxSyncDispatcher (Gmail vs IMAP)

**Files:**
- Create: `src/Mail/MailboxSyncDispatcher.php`
- Test: `tests/MailboxSyncDispatcherTest.php`

- [ ] **Step 1: Írd meg a bukó tesztet**

```php
<?php
declare(strict_types=1);
namespace Tests;

use App\Imap\ImapSyncService;
use App\Gmail\GmailApiSyncService;
use App\Gmail\GmailOAuthService;
use App\Mail\MailboxSyncDispatcher;
use App\Settings\SettingsService;
use App\Support\Encryption;
use PDO;
use PHPUnit\Framework\TestCase;

final class MailboxSyncDispatcherTest extends TestCase
{
    private PDO $pdo;
    private SettingsService $settings;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('CREATE TABLE office_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT, office_id INTEGER, `key` TEXT,
            value_encrypted TEXT, created_at TEXT, updated_at TEXT)');
        $this->settings = new SettingsService($this->pdo, new Encryption(Encryption::generateKey()));
    }

    private function dispatcher(): MailboxSyncDispatcher
    {
        $gmail = new class($this->pdo) extends GmailApiSyncService {
            public function __construct(private PDO $p) {}
            public function syncOffice(int $officeId, int $limit = 25): array { return ['count' => 5, 'error' => null]; }
        };
        $imap = new class extends ImapSyncService {
            public function __construct() {}
            public function isConfigured(int $officeId): bool { return false; }
            public function syncOffice(int $officeId, int $limit = 25): array { return ['count' => 2, 'error' => null]; }
        };
        return new MailboxSyncDispatcher($this->settings, $imap, $gmail);
    }

    public function testUsesGmailWhenRefreshTokenPresent(): void
    {
        $this->settings->set(1, 'google_refresh_token', 'RT');
        $r = $this->dispatcher()->sync(1);
        self::assertSame('gmail', $r['via']);
        self::assertSame(5, $r['count']);
    }

    public function testUsesImapWhenOnlyImapHost(): void
    {
        $this->settings->set(1, 'imap_host', 'imap.example.com');
        $r = $this->dispatcher()->sync(1);
        self::assertSame('imap', $r['via']);
        self::assertSame(2, $r['count']);
    }

    public function testNoneWhenNothingConfigured(): void
    {
        $r = $this->dispatcher()->sync(1);
        self::assertSame('none', $r['via']);
        self::assertNotNull($r['error']);
    }
}
```

- [ ] **Step 2: Futtasd, hogy bukjon**

Run: `vendor/bin/phpunit tests/MailboxSyncDispatcherTest.php`
Expected: FAIL — „Class App\Mail\MailboxSyncDispatcher not found".

- [ ] **Step 3: Implementáció**

`src/Mail/MailboxSyncDispatcher.php`:
```php
<?php
declare(strict_types=1);
namespace App\Mail;

use App\Gmail\GmailApiSyncService;
use App\Imap\ImapSyncService;
use App\Settings\SettingsService;

/**
 * Eldönti, honnan szinkronizáljuk egy iroda beérkező leveleit: ha van Gmail
 * OAuth-kapcsolat → Gmail API; különben ha van IMAP-host → IMAP; egyébként semmi.
 */
final class MailboxSyncDispatcher
{
    public function __construct(
        private SettingsService $settings,
        private ImapSyncService $imap,
        private GmailApiSyncService $gmail,
    ) {
    }

    public function isConfigured(int $officeId): bool
    {
        return $this->hasGmail($officeId)
            || ((string) $this->settings->get($officeId, 'imap_host', '')) !== '';
    }

    /** @return array{count:int, error:?string, via:string} */
    public function sync(int $officeId, int $limit = 25): array
    {
        if ($this->hasGmail($officeId)) {
            return $this->gmail->syncOffice($officeId, $limit) + ['via' => 'gmail'];
        }
        if (((string) $this->settings->get($officeId, 'imap_host', '')) !== '') {
            return $this->imap->syncOffice($officeId, $limit) + ['via' => 'imap'];
        }
        return ['count' => 0, 'error' => 'Nincs postafiók beállítva.', 'via' => 'none'];
    }

    private function hasGmail(int $officeId): bool
    {
        return ((string) $this->settings->get($officeId, 'google_refresh_token', '')) !== '';
    }
}
```

- [ ] **Step 4: Futtasd, hogy átmenjen**

Run: `vendor/bin/phpunit tests/MailboxSyncDispatcherTest.php`
Expected: PASS (3 teszt).

- [ ] **Step 5: Futtasd a teljes tesztkészletet**

Run: `vendor/bin/phpunit`
Expected: minden zöld (a meglévő `TenantIsolationTest` is).

- [ ] **Step 6: Commit**

```bash
git add src/Mail/MailboxSyncDispatcher.php tests/MailboxSyncDispatcherTest.php
git commit -m "feat: MailboxSyncDispatcher — Gmail API vs IMAP választás"
```

---

## Task 5: Konténer — SignedState bekötése

**Files:**
- Modify: `config/container.php`

`GmailOAuthService`, `GmailApiSyncService`, `MailboxSyncDispatcher` autowire-olnak (a Guzzle Clientet maguk építik). Csak a `SignedState` kell factory-val (APP_KEY string).

- [ ] **Step 1: Add hozzá a definíciót**

`config/container.php` — az `Encryption::class` definíció után illeszd be:
```php
    App\Support\SignedState::class => factory(static function (ContainerInterface $c): App\Support\SignedState {
        return new App\Support\SignedState($c->get('settings')['app']['key']);
    }),
```

- [ ] **Step 2: Ellenőrizd a szintaxist**

Run: `php -l config/container.php`
Expected: „No syntax errors detected".

- [ ] **Step 3: Konténer-smoke — a szolgáltatások felépülnek**

Run:
```bash
php -r 'require "vendor/autoload.php"; $GLOBALS["__app_settings"]=(require "config/settings.php")(__DIR__); $GLOBALS["__app_settings"]["app"]["key"]=App\Support\Encryption::generateKey(); $b=new DI\ContainerBuilder(); $b->addDefinitions(require "config/container.php"); $c=$b->build(); $c->get(App\Support\SignedState::class); echo "OK\n";'
```
Expected: `OK`.

- [ ] **Step 4: Commit**

```bash
git add config/container.php
git commit -m "chore: SignedState konténer-definíció"
```

---

## Task 6: Útvonalak + SettingsController OAuth-akciók

**Files:**
- Modify: `config/routes/admin/settings.php`, `src/Http/Controllers/Admin/SettingsController.php`

- [ ] **Step 1: Útvonalak**

`config/routes/admin/settings.php` — a `save` sor alá:
```php
    $g->get('/beallitasok/gmail/connect', [SettingsController::class, 'gmailConnect']);
    $g->get('/beallitasok/gmail/callback', [SettingsController::class, 'gmailCallback']);
    $g->post('/beallitasok/gmail/disconnect', [SettingsController::class, 'gmailDisconnect']);
```

- [ ] **Step 2: Controller — függőség + redirect-helper + akciók**

`SettingsController.php` — a konstruktorba vedd fel a `GmailOAuthService`-t:
```php
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private SettingsService $settings,
        private AuditLogger $audit,
        private \App\Gmail\GmailOAuthService $gmail,
    ) {
    }
```

A `show()` végén a `googleConnected` mellé add hozzá a Gmail-státuszt (a render-tömbbe):
```php
            'gmailConfigured' => $this->gmail->isConfigured($officeId),
            'gmailConnected' => $this->gmail->isConnected($officeId),
            'gmailEmail' => $this->gmail->connectedEmail($officeId),
            'gmailRedirectUri' => $this->gmailRedirectUri($request),
```

Új privát metódusok és akciók (a `flash()` fölé):
```php
    private function gmailRedirectUri(Request $request): string
    {
        $rc = \Slim\Routing\RouteContext::fromRequest($request);
        $u = $request->getUri();
        return $u->getScheme() . '://' . $u->getAuthority() . $rc->getBasePath() . '/admin/beallitasok/gmail/callback';
    }

    public function gmailConnect(Request $request, Response $response): Response
    {
        $officeId = $this->auth->officeId();
        if ($officeId === null || !$this->gmail->isConfigured($officeId)) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Előbb add meg és mentsd a Google Client ID-t és Secretet.'];
            return $this->redirect($response, '/admin/beallitasok');
        }
        $url = $this->gmail->authUrl($officeId, $this->gmailRedirectUri($request));
        return $response->withHeader('Location', $url)->withStatus(302);
    }

    public function gmailCallback(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $code = (string) ($q['code'] ?? '');
        $state = (string) ($q['state'] ?? '');
        try {
            $result = $this->gmail->handleCallback($code, $state, $this->gmailRedirectUri($request));
            $this->audit->log('settings.gmail.connect');
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Gmail csatlakoztatva: ' . ($result['email'] ?: 'ismeretlen fiók')];
        } catch (\App\Gmail\GmailAuthException $e) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Gmail-csatlakozási hiba: ' . $e->getMessage()];
        }
        return $this->redirect($response, '/admin/beallitasok');
    }

    public function gmailDisconnect(Request $request, Response $response): Response
    {
        $officeId = $this->auth->officeId();
        if ($officeId !== null) {
            $this->gmail->disconnect($officeId);
            $this->audit->log('settings.gmail.disconnect');
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Gmail-kapcsolat leválasztva.'];
        }
        return $this->redirect($response, '/admin/beallitasok');
    }
```

- [ ] **Step 3: Szintaxis-ellenőrzés**

Run: `php -l src/Http/Controllers/Admin/SettingsController.php && php -l config/routes/admin/settings.php`
Expected: mindkettő „No syntax errors detected".

- [ ] **Step 4: Boot-smoke — a route-ok betöltődnek**

Run:
```bash
php -S 127.0.0.1:8799 -t public public/index.php >/tmp/g.log 2>&1 &
sleep 2
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8799/admin/beallitasok/gmail/connect
kill %1
```
Expected: `302` (AuthGuard átirányít a belépésre — a lényeg, hogy nincs 500/route-hiba). Nézd meg `/tmp/g.log`-ot fatal hibára.

- [ ] **Step 5: Commit**

```bash
git add config/routes/admin/settings.php src/Http/Controllers/Admin/SettingsController.php
git commit -m "feat: Gmail OAuth útvonalak és SettingsController akciók"
```

---

## Task 7: Beállítások UI — Gmail-csatlakoztatás

**Files:**
- Modify: `templates/admin/settings/index.twig`

A „Google Naptár" fület Gmail-csatlakoztatásra alakítjuk (a client_id/secret mezők maradnak).

- [ ] **Step 1: Cseréld le a Google fül tartalmát**

`templates/admin/settings/index.twig` — a `{# Google Naptár #}` `<div x-show="tab === 'google'" ...>` blokk teljes tartalmát (a `<h2>`-től a záró `</div>`-ig, a 95–113. sorok) erre:
```twig
  {# Gmail (OAuth) #}
  <div x-show="tab === 'google'" x-cloak class="rounded-2xl border border-slate-200/70 bg-white p-6 shadow-card sm:p-7">
    <div class="flex items-center justify-between gap-3">
      <h2 class="text-[15px] font-bold text-ink">Gmail (OAuth)</h2>
      {% if gmailConnected %}
        <span class="flex items-center gap-1.5 rounded-full border border-success/20 bg-success/5 px-3 py-1.5 text-[12.5px] font-semibold text-success">
          <i data-lucide="check-circle-2" class="h-4 w-4"></i> Összekötve{% if gmailEmail %} · {{ gmailEmail }}{% endif %}
        </span>
      {% else %}
        <span class="flex items-center gap-1.5 rounded-full border border-slate-200 bg-surface px-3 py-1.5 text-[12.5px] font-semibold text-muted">
          <i data-lucide="x-circle" class="h-4 w-4"></i> Nincs összekötve
        </span>
      {% endif %}
    </div>

    <p class="mt-3 text-[12.5px] text-muted">A Gmail-behúzás a Google API-n keresztül megy (HTTPS), így a tárhely tiltott IMAP-portja nem gátolja. Hozz létre egy Google Cloud OAuth-klienst, engedélyezd a Gmail API-t, és add meg lent a Client ID-t és Secretet.</p>

    <div class="mt-4 rounded-xl border border-slate-200 bg-surface px-4 py-3">
      <div class="text-[11px] font-semibold uppercase tracking-wide text-muted">Átirányítási URI (ezt írd be a Google-kliensbe)</div>
      <div class="mt-1 break-all font-mono text-[12.5px] text-ink">{{ gmailRedirectUri }}</div>
    </div>

    <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
      {{ f.field('Client ID', 'google_client_id', v, 'text', '...apps.googleusercontent.com') }}
      {{ f.secret('Client Secret', 'google_client_secret', secret.google_client_secret_set) }}
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-3">
      {% if gmailConnected %}
        <a href="/admin/beallitasok/gmail/connect" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-[13.5px] font-semibold text-ink transition hover:bg-surface"><i data-lucide="refresh-cw" class="h-[17px] w-[17px]"></i> Újracsatlakoztatás</a>
      {% else %}
        <a href="/admin/beallitasok/gmail/connect" class="inline-flex items-center gap-2 rounded-xl bg-ink px-4 py-2.5 text-[13.5px] font-semibold text-white transition hover:bg-ink/90"><i data-lucide="link" class="h-[17px] w-[17px]"></i> Gmail csatlakoztatása</a>
      {% endif %}
    </div>
    <p class="mt-2 text-[12px] text-muted">A csatlakoztatás előtt mentsd a Client ID-t és Secretet a „Mentés" gombbal.</p>
  </div>

  {% if gmailConnected %}
    <form method="post" action="/admin/beallitasok/gmail/disconnect" x-show="tab === 'google'" x-cloak class="mx-auto mt-3 max-w-3xl">
      <input type="hidden" name="{{ csrf.keys.name }}" value="{{ csrf.name }}">
      <input type="hidden" name="{{ csrf.keys.value }}" value="{{ csrf.value }}">
      <button type="submit" class="inline-flex items-center gap-2 rounded-xl border border-danger/20 bg-white px-4 py-2.5 text-[13px] font-semibold text-danger transition hover:bg-danger/5"><i data-lucide="unlink" class="h-[16px] w-[16px]"></i> Gmail leválasztása</button>
    </form>
  {% endif %}
```

Frissítsd a fül-címkét is: a `tabs` tömbben a `google` sor `label`-jét `'Google Naptár'`-ról `'Gmail'`-re.

> Megjegyzés: a leválasztó űrlap külön `<form>`, mert a fő beállító-form POST-ja a `/admin/beallitasok`-ra megy; a beágyazott form nem megengedett. Az `x-show`-val ugyanabban a fülben jelenik meg.

- [ ] **Step 2: Twig-lint**

Run:
```bash
cp "<scratchpad>/twiglint.php" ./_twiglint.php && php _twiglint.php | grep -E "settings|ERR|OK="; rm -f _twiglint.php
```
Expected: `OK=56 ERR=0` (a settings sablon hibátlan). *(A `<scratchpad>` a session twiglint.php-je; ha nincs kéznél, egy minimális FilesystemLoader-es parse-szkript is jó.)*

- [ ] **Step 3: Commit**

```bash
git add templates/admin/settings/index.twig
git commit -m "feat: Beállítások — Gmail OAuth csatlakoztatás UI"
```

---

## Task 8: InboxController és imap:sync átkötése a diszpécserre

**Files:**
- Modify: `src/Http/Controllers/Admin/InboxController.php`, `src/Console/ImapSyncCommand.php`

- [ ] **Step 1: InboxController — diszpécser**

`InboxController.php`:
- A konstruktorban cseréld: `private ImapSyncService $imap,` → `private \App\Mail\MailboxSyncDispatcher $mailbox,`
- A `use App\Imap\ImapSyncService;` sort cseréld: `use App\Mail\MailboxSyncDispatcher;`
- Az `index()`-ben: `'configured' => $this->imap->isConfigured($officeId),` → `'configured' => $this->mailbox->isConfigured($officeId),`
- A `sync()`-ben: `$result = $this->imap->syncOffice($officeId);` → `$result = $this->mailbox->sync($officeId);`

- [ ] **Step 2: ImapSyncCommand — diszpécser**

`ImapSyncCommand.php`:
- `use App\Imap\ImapSyncService;` → `use App\Mail\MailboxSyncDispatcher;`
- Konstruktor: `private ImapSyncService $imap,` → `private MailboxSyncDispatcher $mailbox,`
- Az `execute()` ciklusmagja:
```php
        foreach ($offices as $office) {
            $id = (int) $office['id'];
            if (!$this->mailbox->isConfigured($id)) {
                continue;
            }
            $result = $this->mailbox->sync($id);
            if ($result['error'] !== null) {
                $output->writeln(sprintf('[%s] hiba: %s', $office['name'], $result['error']));
            } else {
                $output->writeln(sprintf('[%s] %d új levél (%s).', $office['name'], $result['count'], $result['via']));
            }
        }
```

- [ ] **Step 3: Szintaxis + teljes teszt**

Run: `php -l src/Http/Controllers/Admin/InboxController.php && php -l src/Console/ImapSyncCommand.php && vendor/bin/phpunit`
Expected: nincs szintaxishiba; minden teszt zöld.

- [ ] **Step 4: CLI-smoke — a parancs felépül**

Run: `php bin/console imap:sync 2>&1 | head -5`
Expected: lefut hiba nélkül (0 aktív iroda esetén üres kimenet; a lényeg, hogy a DI felépíti a `MailboxSyncDispatcher`-t, nincs „not found").

- [ ] **Step 5: Commit**

```bash
git add src/Http/Controllers/Admin/InboxController.php src/Console/ImapSyncCommand.php
git commit -m "feat: postaláda-szinkron a MailboxSyncDispatcheren át (Gmail vagy IMAP)"
```

---

## Task 9: `.env.example` + éles kézi végpróba

**Files:**
- Modify: `.env.example`

- [ ] **Step 1: APP_URL megjegyzés**

`.env.example` — az `APP_URL` sorhoz (vagy ha nincs, add hozzá) tedd a megjegyzést:
```
# A Gmail OAuth redirect URI ebből + /admin/beallitasok/gmail/callback áll össze.
# Alkönyvtáras telepítésnél a teljes bázis kell, pl. https://visualbyadam.hu/zsolti_crm
APP_URL=https://visualbyadam.hu/zsolti_crm
```

- [ ] **Step 2: Commit**

```bash
git add .env.example
git commit -m "docs: APP_URL megjegyzés a Gmail redirect URI-hoz"
```

- [ ] **Step 3: Éles kézi végpróba (a szerveren, deploy után)**

Ellenőrző lista (nincs automata teszt, valós Google-fiók kell):
1. Google Cloud: projekt → Gmail API ON → OAuth consent (External, scope `gmail.readonly`, In production) → OAuth Web kliens; a „Authorized redirect URIs"-be a Beállítások-fülön mutatott URI.
2. CRM → Beállítások → Gmail: Client ID + Secret → **Mentés**.
3. **Gmail csatlakoztatása** → Google consent (nem verifikált appnál: Speciális → Tovább) → visszatér „Gmail csatlakoztatva: …" flash-sel.
4. Postaláda → **Szinkronizálás** → „N új levél szinkronizálva" (nem „connection failed").
5. Cron marad ugyanaz (`imap:sync`); mostantól Gmailnél is behúz.

---

## Self-Review

**Spec-lefedettség:**
- OAuth2 per-iroda folyam → Task 2. ✓
- Gmail API behúzás + törzs (443, megkerüli 993) → Task 3. ✓
- IMAP megtartása + diszpécser → Task 4, 8. ✓
- Beállítások UI (csatlakoztat/leválaszt/állapot/redirect URI) → Task 7. ✓
- Aláírt `state` (CSRF + officeId) → Task 1, használat Task 2. ✓
- refresh_token + client_secret titkosítva → a meglévő `SECRET_KEYS` (SettingsService) fedi; Task 2 ezekre ír. ✓
- Hibakezelés `invalid_grant` → leválasztás → Task 2 (`accessTokenInvalidGrantDisconnects` teszt). ✓
- Nincs séma-migráció → csak `office_settings` kulcsok; a `gmail_email`/`gmail_connected_at` plain kulcsok, a `google_refresh_token` már a whitelistán. ✓
- Tesztek: state, OAuth (mock), parser (fixtures), diszpécser → Task 1–4. ✓
- Előfeltételek (Google Cloud) → Task 9 kézi lista. ✓

**Placeholder-ellenőrzés:** nincs TBD/„handle errors" — minden lépésben konkrét kód. A Task 7 `<scratchpad>` hivatkozás a session twiglint-útját jelöli, alternatívával. ✓

**Típus-konzisztencia:** `syncOffice(int,int):{count,error}` egységes IMAP↔Gmail; `MailboxSyncDispatcher::sync():{count,error,via}`; `SignedState::sign/verify`; `GmailOAuthService::{authUrl,handleCallback,accessToken,disconnect,isConfigured,isConnected,connectedEmail}` — a tesztek és a controller ugyanezeket hívják. ✓

> **Figyelem a végrehajtónak:** a Task 2/3/4 tesztek anonim osztályokkal `extends`-elik a valós szolgáltatásokat, üres konstruktorral. Ez megköveteli, hogy a `GmailOAuthService`, `GmailApiSyncService`, `ImapSyncService` **ne legyen `final`** (a `GmailApiSyncService`/`GmailOAuthService` a fenti kódban nem `final`; az `ImapSyncService` jelenleg `final` — a Task 4 előtt vedd le róla a `final`-t, vagy a teszt használjon egyszerű saját dublőrt interfész nélkül). A végrehajtó a Task 4 Step 1 előtt ellenőrizze: ha `ImapSyncService` `final`, törölje a `final` kulcsszót és külön commitolja: `refactor: ImapSyncService nem final (tesztelhetőség)`.
