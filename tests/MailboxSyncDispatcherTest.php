<?php

declare(strict_types=1);

namespace Tests;

use App\Gmail\GmailApiSyncService;
use App\Imap\ImapSyncService;
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
            public function __construct(private PDO $p)
            {
            }

            public function syncOffice(int $officeId, int $limit = 25): array
            {
                return ['count' => 5, 'error' => null];
            }
        };
        $imap = new class extends ImapSyncService {
            public function __construct()
            {
            }

            public function isConfigured(int $officeId): bool
            {
                return false;
            }

            public function syncOffice(int $officeId, int $limit = 25): array
            {
                return ['count' => 2, 'error' => null];
            }
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
