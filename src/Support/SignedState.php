<?php

declare(strict_types=1);

namespace App\Support;

/**
 * HMAC-aláírt, lejáró állapot-token (pl. OAuth `state`). A payload base64url-ben
 * utazik, az aláírás az APP_KEY-jel készül. Nem titkosít, csak hitelesít.
 */
final class SignedState
{
    public function __construct(private string $key)
    {
    }

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
        if (count($parts) !== 2) {
            return null;
        }
        [$payload, $sig] = $parts;
        if (!hash_equals($this->mac($payload), $sig)) {
            return null;
        }
        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['exp']) || (int) $data['exp'] < time()) {
            return null;
        }

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
