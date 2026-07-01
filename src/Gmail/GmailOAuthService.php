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
        private string $appClientId = '',
        private string $appClientSecret = '',
    ) {
        $this->http = $http ?? new Client(['timeout' => 30]);
    }

    /** Az iroda saját kliense elsőbbséget élvez; ha nincs, az app-szintű (.env) kliens. */
    private function clientId(int $officeId): string
    {
        $v = (string) $this->settings->get($officeId, 'google_client_id', '');

        return $v !== '' ? $v : $this->appClientId;
    }

    private function clientSecret(int $officeId): string
    {
        $v = (string) $this->settings->get($officeId, 'google_client_secret', '');

        return $v !== '' ? $v : $this->appClientSecret;
    }

    /** Van-e app-szintű (közös) kliens → ekkor az iroda egy kattintással köthet. */
    public function appConfigured(): bool
    {
        return $this->appClientId !== '' && $this->appClientSecret !== '';
    }

    public function isConfigured(int $officeId): bool
    {
        return $this->clientId($officeId) !== '' && $this->clientSecret($officeId) !== '';
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
            'client_id' => $this->clientId($officeId),
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
                'client_id' => $this->clientId($officeId),
                'client_secret' => $this->clientSecret($officeId),
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
                'client_id' => $this->clientId($officeId),
                'client_secret' => $this->clientSecret($officeId),
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
