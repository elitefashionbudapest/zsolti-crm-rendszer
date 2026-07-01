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
            public function __construct()
            {
            }

            public function accessToken(int $officeId): string
            {
                return 'AT';
            }
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

    /** @param list<Response> $responses */
    private function makeService(array $responses): GmailApiSyncService
    {
        $http = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);

        return new GmailApiSyncService($this->pdo, $this->oauthStub(), $http);
    }
}
