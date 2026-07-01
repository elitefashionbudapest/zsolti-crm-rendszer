<?php

declare(strict_types=1);

namespace App\Imap;

use App\Documents\DocumentStorage;
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
        private ?DocumentStorage $storage = null,
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
                $bodyHtml = (string) ($message->getHTMLBody() ?? '');
                $date = $message->getDate();
                $receivedAt = $date ? $date->toDateTimeString() : date('Y-m-d H:i:s');

                $emailId = $this->store($officeId, $uid, $from, $subject, mb_substr($body, 0, 5000), mb_substr($bodyHtml, 0, 300000), $receivedAt);

                if ($this->storage !== null) {
                    foreach ($message->getAttachments() as $attachment) {
                        $content = (string) ($attachment->getContent() ?? '');
                        if ($content === '') {
                            continue;
                        }
                        $name = (string) ($attachment->getName() ?? 'melleklet');
                        $mime = (string) ($attachment->getMimeType() ?? 'application/octet-stream');
                        $meta = $this->storage->saveBytes($officeId, $content, $name, $mime);
                        $this->storeAttachment($officeId, $emailId, $meta);
                    }
                }
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

    private function store(int $officeId, string $uid, string $from, string $subject, string $body, string $bodyHtml, string $receivedAt): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO incoming_emails (office_id, message_uid, from_email, subject, body, body_html, received_at, created_at, updated_at)
             VALUES (:o, :u, :f, :s, :b, :h, :r, :c, :up)'
        );
        $stmt->execute([
            'o' => $officeId, 'u' => $uid !== '' ? $uid : null, 'f' => $from,
            's' => mb_substr($subject, 0, 255), 'b' => $body, 'h' => $bodyHtml !== '' ? $bodyHtml : null,
            'r' => $receivedAt, 'c' => $now, 'up' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array{stored_path:string, original_name:string, mime:string, size:int} $meta */
    private function storeAttachment(int $officeId, int $emailId, array $meta): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO incoming_email_attachments (office_id, email_id, filename, mime, size_bytes, stored_path, created_at, updated_at)
             VALUES (:o, :e, :f, :m, :s, :p, :c, :up)'
        );
        $stmt->execute([
            'o' => $officeId, 'e' => $emailId, 'f' => mb_substr($meta['original_name'], 0, 255),
            'm' => $meta['mime'], 's' => $meta['size'], 'p' => $meta['stored_path'], 'c' => $now, 'up' => $now,
        ]);
    }
}
