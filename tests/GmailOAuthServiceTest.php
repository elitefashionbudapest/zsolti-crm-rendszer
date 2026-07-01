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

    /** @param list<Response> $responses */
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
