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
                if ($uid !== '' && $this->exists($officeId, $uid)) {
                    continue;
                }

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
