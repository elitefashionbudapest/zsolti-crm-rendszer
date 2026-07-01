<?php

declare(strict_types=1);

namespace App\Ai;

use GuzzleHttp\Client;
use RuntimeException;

/**
 * Vékony kliens az Anthropic Claude Messages API-hoz (raw HTTP, Guzzle).
 * Dokumentumból (PDF/kép) strukturált adatkinyerés JSON-séma szerinti kimenettel.
 */
final class ClaudeClient
{
    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client([
            'base_uri' => 'https://api.anthropic.com',
            'timeout' => 300,
            'connect_timeout' => 15,
        ]);
    }

    /**
     * Adatkinyerés egy dokumentumból. A $schema egy JSON Schema (object/properties).
     *
     * @param array<string,mixed> $schema
     * @return array<string,mixed> a kinyert mezők
     */
    public function extract(string $fileBinary, string $mime, string $apiKey, string $model, array $schema, string $instruction): array
    {
        if ($apiKey === '') {
            throw new RuntimeException('Hiányzik az Anthropic API kulcs (Beállítások).');
        }

        $isPdf = str_contains($mime, 'pdf');
        $source = [
            'type' => 'base64',
            'media_type' => $isPdf ? 'application/pdf' : ($mime !== '' ? $mime : 'image/png'),
            'data' => base64_encode($fileBinary),
        ];
        $docBlock = $isPdf
            ? ['type' => 'document', 'source' => $source]
            : ['type' => 'image', 'source' => $source];

        $payload = [
            'model' => $model !== '' ? $model : 'claude-opus-4-8',
            'max_tokens' => 4096,
            // Streaming: a válasz folyamatosan érkezik, így a hosszabb feldolgozás
            // sem fut bele a „0 bájt / timeout" curl-hibába (shared hostingon fontos).
            'stream' => true,
            'output_config' => [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => $schema,
                ],
            ],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    $docBlock,
                    ['type' => 'text', 'text' => $instruction],
                ],
            ]],
        ];

        // A PHP futásidő-limitet is megemeljük a hosszú AI-hívás miatt (best-effort).
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        try {
            $resp = $this->http->post('/v1/messages', [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                    'accept' => 'text/event-stream',
                ],
                'json' => $payload,
                'stream' => true,
            ]);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            // A 400/4xx válasz törzse tartalmazza az API valódi hibaüzenetét — hozzuk felszínre.
            $detail = $e->getMessage();
            if ($e->hasResponse()) {
                $body = json_decode((string) $e->getResponse()->getBody(), true);
                if (isset($body['error']['message'])) {
                    $detail = (string) $body['error']['message'];
                }
            }
            throw new RuntimeException('Claude API hívás sikertelen: ' . $detail, 0, $e);
        } catch (\Throwable $e) {
            throw new RuntimeException('Claude API hívás sikertelen: ' . $e->getMessage(), 0, $e);
        }

        $text = $this->readStream($resp->getBody());

        $fields = json_decode($text, true);

        return is_array($fields) ? $fields : [];
    }

    /**
     * A Claude SSE-stream (server-sent events) szöveges deltáinak összefűzése.
     */
    private function readStream(\Psr\Http\Message\StreamInterface $body): string
    {
        $text = '';
        $buffer = '';

        while (!$body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '') {
                continue;
            }
            $buffer .= $chunk;

            while (($nl = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $nl));
                $buffer = substr($buffer, $nl + 1);

                if (!str_starts_with($line, 'data:')) {
                    continue;
                }
                $json = trim(substr($line, 5));
                if ($json === '' || $json === '[DONE]') {
                    continue;
                }
                $ev = json_decode($json, true);
                if (!is_array($ev)) {
                    continue;
                }

                $type = (string) ($ev['type'] ?? '');
                if ($type === 'content_block_delta' && (($ev['delta']['type'] ?? '') === 'text_delta')) {
                    $text .= (string) ($ev['delta']['text'] ?? '');
                } elseif ($type === 'message_delta' && (($ev['delta']['stop_reason'] ?? '') === 'refusal')) {
                    throw new RuntimeException('A modell elutasította a kérést.');
                } elseif ($type === 'error') {
                    throw new RuntimeException('Claude API hiba: ' . (string) ($ev['error']['message'] ?? 'ismeretlen'));
                }
            }
        }

        return $text;
    }

    /**
     * Szabad szöveg (HTML) generálása egy utasításból — a tanácsadói anyagokhoz.
     */
    public function complete(string $instruction, string $apiKey, string $model, int $maxTokens = 2500): string
    {
        if ($apiKey === '') {
            throw new RuntimeException('Hiányzik az Anthropic API kulcs (Beállítások).');
        }

        $payload = [
            'model' => $model !== '' ? $model : 'claude-opus-4-8',
            'max_tokens' => $maxTokens,
            'messages' => [[
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => $instruction]],
            ]],
        ];

        try {
            $resp = $this->http->post('/v1/messages', [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ],
                'json' => $payload,
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Claude API hívás sikertelen: ' . $e->getMessage(), 0, $e);
        }

        $data = json_decode((string) $resp->getBody(), true);
        if (!is_array($data)) {
            throw new RuntimeException('Érvénytelen Claude válasz.');
        }
        if (($data['stop_reason'] ?? null) === 'refusal') {
            throw new RuntimeException('A modell elutasította a kérést.');
        }

        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        // Esetleges ```html ... ``` kódkeret eltávolítása.
        $text = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($text)) ?? $text;

        return trim($text);
    }

    /**
     * Az ügyfél-/szerződésadatok kinyeréséhez használt séma és utasítás.
     *
     * @return array{schema: array<string,mixed>, instruction: string}
     */
    public static function clientContractSchema(): array
    {
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'client_name' => ['type' => 'string'],
                'client_email' => ['type' => 'string'],
                'client_phone' => ['type' => 'string'],
                'client_address' => ['type' => 'string'],
                'tax_id' => ['type' => 'string'],
                'birth_date' => ['type' => 'string'],
                'birth_place' => ['type' => 'string'],
                'mother_name' => ['type' => 'string'],
                'insurer_name' => ['type' => 'string'],
                'module_name' => ['type' => 'string'],
                'policy_number' => ['type' => 'string'],
                'offer_number' => ['type' => 'string'],
                'start_date' => ['type' => 'string'],
                'end_date' => ['type' => 'string'],
                'annual_fee' => ['type' => 'string'],
                'plate' => ['type' => 'string'],
            ],
        ];
        // A strukturált kimenet minden mezőt kötelezőnek vár; az ismeretlen
        // értékeket a modell üres stringgel tölti (lásd az utasítást).
        $schema['required'] = array_keys($schema['properties']);

        $instruction = 'Nyerd ki a dokumentumból az ügyfélre és a biztosítási '
            . 'szerződésre vonatkozó adatokat a megadott mezőkbe. Ahol egy adat nem '
            . 'szerepel a dokumentumban, hagyd üresen ("" érték). A dátumokat '
            . 'ÉÉÉÉ-HH-NN formátumban add meg. Csak a kért JSON-t add vissza.';

        return ['schema' => $schema, 'instruction' => $instruction];
    }
}
