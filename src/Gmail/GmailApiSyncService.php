<?php

declare(strict_types=1);

namespace App\Gmail;

use App\Documents\DocumentStorage;
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
        private ?DocumentStorage $storage = null,
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
                if ($uid !== '' && $this->exists($officeId, $uid)) {
                    continue;
                }

                $from = $this->extractEmail($this->header($payload, 'From'));
                $subject = $this->header($payload, 'Subject');
                $body = $this->extractBody($payload);
                $bodyHtml = $this->collect($payload, 'text/html');
                $received = $this->parseDate($this->header($payload, 'Date'));

                $emailId = $this->store($officeId, $uid, $from, $subject, mb_substr($body, 0, 5000), mb_substr($bodyHtml, 0, 300000), $received);

                if ($this->storage !== null) {
                    foreach ($this->attachments($payload) as $att) {
                        $bytes = $this->downloadAttachment($id, $att, $headers);
                        if ($bytes === '') {
                            continue;
                        }
                        $meta = $this->storage->saveBytes($officeId, $bytes, $att['filename'], $att['mime']);
                        $this->storeAttachment($officeId, $emailId, $meta);
                    }
                }
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

    /**
     * A payload-fa bejárása: elsőbbség text/plain-nek, fallback text/html→szöveg.
     *
     * @param array<string,mixed> $payload
     */
    private function extractBody(array $payload): string
    {
        $plain = $this->collect($payload, 'text/plain');
        if ($plain !== '') {
            return $plain;
        }
        $html = $this->collect($payload, 'text/html');

        return $html !== '' ? trim(html_entity_decode(strip_tags($html))) : '';
    }

    /** @param array<string,mixed> $node */
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
        if (preg_match('/<([^>]+)>/', $from, $m)) {
            return trim($m[1]);
        }

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

    /**
     * Mellékletek a payload-fából: {filename, mime, attachmentId, data}.
     *
     * @param array<string,mixed> $node
     * @return list<array{filename:string, mime:string, attachmentId:string, data:string}>
     */
    private function attachments(array $node, array &$out = []): array
    {
        $filename = (string) ($node['filename'] ?? '');
        if ($filename !== '') {
            $out[] = [
                'filename' => $filename,
                'mime' => (string) ($node['mimeType'] ?? 'application/octet-stream'),
                'attachmentId' => (string) ($node['body']['attachmentId'] ?? ''),
                'data' => (string) ($node['body']['data'] ?? ''),
            ];
        }
        foreach ($node['parts'] ?? [] as $part) {
            $this->attachments($part, $out);
        }

        return $out;
    }

    /** @param array{filename:string, mime:string, attachmentId:string, data:string} $att */
    private function downloadAttachment(string $messageId, array $att, array $headers): string
    {
        if ($att['data'] !== '') {
            return $this->b64urlDecode($att['data']);
        }
        if ($att['attachmentId'] === '') {
            return '';
        }
        try {
            $res = $this->http->get(self::BASE . '/messages/' . $messageId . '/attachments/' . $att['attachmentId'], ['headers' => $headers]);
            $json = json_decode((string) $res->getBody(), true) ?: [];

            return $this->b64urlDecode((string) ($json['data'] ?? ''));
        } catch (Throwable) {
            return '';
        }
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
