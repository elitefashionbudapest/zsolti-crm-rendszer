<?php

declare(strict_types=1);

namespace App\Imap;

use App\Settings\SettingsService;
use PDO;
use Throwable;
use Webklex\PHPIMAP\ClientManager;

/**
 * Beérkező levelek szinkronizálása az iroda IMAP-beállításaiból az
 * incoming_emails táblába. Hiba esetén nem dob — visszaadja a hibát.
 */
class ImapSyncService
{
    public function __construct(
        private PDO $pdo,
        private SettingsService $settings,
    ) {
    }

    public function isConfigured(int $officeId): bool
    {
        return (string) $this->settings->get($officeId, 'imap_host', '') !== '';
    }

    /**
     * @return array{count: int, error: ?string}
     */
    public function syncOffice(int $officeId, int $limit = 25): array
    {
        $host = (string) $this->settings->get($officeId, 'imap_host', '');
        if ($host === '') {
            return ['count' => 0, 'error' => 'Nincs IMAP beállítva.'];
        }

        $port = (int) ($this->settings->get($officeId, 'imap_port', '993') ?? '993');
        $username = (string) $this->settings->get($officeId, 'imap_username', '');
        $password = (string) $this->settings->get($officeId, 'imap_password', '');

        try {
            $cm = new ClientManager();
            $client = $cm->make([
                'host' => $host,
                'port' => $port,
                'encryption' => $port === 143 ? false : 'ssl',
                'validate_cert' => true,
                'username' => $username,
                'password' => $password,
                'protocol' => 'imap',
            ]);
            $client->connect();

            $folder = $client->getFolder('INBOX');
            if ($folder === null) {
                return ['count' => 0, 'error' => 'Nincs INBOX mappa.'];
            }

            $messages = $folder->query()->limit($limit)->setFetchOrder('desc')->get();

            $stored = 0;
            foreach ($messages as $message) {
                $uid = (string) ($message->getMessageId() ?? $message->getUid() ?? '');
                if ($uid !== '' && $this->exists($officeId, $uid)) {
                    continue;
                }
                $fromArr = $message->getFrom();
                $from = isset($fromArr[0]) ? (string) ($fromArr[0]->mail ?? '') : '';
                $subject = (string) ($message->getSubject() ?? '');
                $body = (string) ($message->getTextBody() ?? '');
                $date = $message->getDate();
                $receivedAt = $date ? $date->toDateTimeString() : date('Y-m-d H:i:s');

                $this->store($officeId, $uid, $from, $subject, mb_substr($body, 0, 5000), $receivedAt);
                $stored++;
            }

            return ['count' => $stored, 'error' => null];
        } catch (Throwable $e) {
            return ['count' => 0, 'error' => $e->getMessage()];
        }
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
