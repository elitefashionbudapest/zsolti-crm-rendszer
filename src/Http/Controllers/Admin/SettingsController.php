<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\Auth;
use App\Settings\SettingsService;
use App\Support\AuditLogger;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Irodai beállítások kezelése: e-mail (SMTP/IMAP), AI (Claude), Google Naptár és
 * branding. A titkos kulcsokat a SettingsService titkosítva tárolja, és a
 * szerkesztőfelület soha nem küldi vissza a nyers értéküket a sablonnak.
 */
final class SettingsController
{
    /** Nem titkos kulcsok: az értékük előtöltődik az űrlapba. */
    private const PLAIN_KEYS = [
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_from_email', 'smtp_from_name',
        'imap_host', 'imap_port', 'imap_username',
        'anthropic_model',
        'google_client_id',
        'brand_name', 'brand_primary_color',
    ];

    /** Titkos kulcsok: csak akkor írjuk felül, ha új értéket küldenek. */
    private const SECRET_KEYS = [
        'smtp_password', 'imap_password', 'anthropic_api_key', 'google_client_secret',
    ];

    /** Alapértelmezett értékek néhány kulcshoz. */
    private const DEFAULTS = [
        'anthropic_model' => 'claude-opus-4-8',
        'brand_primary_color' => '#0F2A4A',
    ];

    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private SettingsService $settings,
        private AuditLogger $audit,
        private \App\Gmail\GmailOAuthService $gmail,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $officeId = $this->auth->officeId();
        if ($officeId === null) {
            return $this->twig->render($response, 'admin/settings/index.twig', [
                'active' => 'settings',
                'noOffice' => true,
                'flash' => $this->flash(),
            ]);
        }

        $values = [];
        foreach (self::PLAIN_KEYS as $key) {
            $values[$key] = $this->settings->get($officeId, $key, self::DEFAULTS[$key] ?? null);
        }

        $secretSet = [];
        foreach (self::SECRET_KEYS as $key) {
            $value = $this->settings->get($officeId, $key);
            $secretSet[$key . '_set'] = $value !== null && $value !== '';
        }

        $googleConnected = ($this->settings->get($officeId, 'google_refresh_token') ?? '') !== '';

        return $this->twig->render($response, 'admin/settings/index.twig', [
            'active' => 'settings',
            'noOffice' => false,
            'v' => $values,
            'secret' => $secretSet,
            'googleConnected' => $googleConnected,
            'gmailConfigured' => $this->gmail->isConfigured($officeId),
            'gmailAppConfigured' => $this->gmail->appConfigured(),
            'gmailConnected' => $this->gmail->isConnected($officeId),
            'gmailEmail' => $this->gmail->connectedEmail($officeId),
            'gmailRedirectUri' => $this->gmailRedirectUri($request),
            'flash' => $this->flash(),
        ]);
    }

    private function gmailRedirectUri(Request $request): string
    {
        $rc = \Slim\Routing\RouteContext::fromRequest($request);
        $u = $request->getUri();

        return $u->getScheme() . '://' . $u->getAuthority() . $rc->getBasePath() . '/admin/beallitasok/gmail/callback';
    }

    public function gmailConnect(Request $request, Response $response): Response
    {
        $officeId = $this->auth->officeId();
        if ($officeId === null || !$this->gmail->isConfigured($officeId)) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Előbb add meg és mentsd a Google Client ID-t és Secretet.'];

            return $this->redirect($response, '/admin/beallitasok');
        }
        $url = $this->gmail->authUrl($officeId, $this->gmailRedirectUri($request));

        return $response->withHeader('Location', $url)->withStatus(302);
    }

    public function gmailCallback(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $code = (string) ($q['code'] ?? '');
        $state = (string) ($q['state'] ?? '');
        try {
            $result = $this->gmail->handleCallback($code, $state, $this->gmailRedirectUri($request));
            $this->audit->log('settings.gmail.connect');
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Gmail csatlakoztatva: ' . ($result['email'] ?: 'ismeretlen fiók')];
        } catch (\App\Gmail\GmailAuthException $e) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Gmail-csatlakozási hiba: ' . $e->getMessage()];
        }

        return $this->redirect($response, '/admin/beallitasok');
    }

    public function gmailDisconnect(Request $request, Response $response): Response
    {
        $officeId = $this->auth->officeId();
        if ($officeId !== null) {
            $this->gmail->disconnect($officeId);
            $this->audit->log('settings.gmail.disconnect');
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Gmail-kapcsolat leválasztva.'];
        }

        return $this->redirect($response, '/admin/beallitasok');
    }

    public function save(Request $request, Response $response): Response
    {
        $officeId = $this->auth->officeId();
        if ($officeId === null) {
            return $this->redirect($response, '/admin/beallitasok');
        }

        $body = (array) $request->getParsedBody();

        foreach (self::PLAIN_KEYS as $key) {
            $val = trim((string) ($body[$key] ?? ''));
            $this->settings->set($officeId, $key, $val === '' ? null : $val);
        }

        foreach (self::SECRET_KEYS as $key) {
            $val = trim((string) ($body[$key] ?? ''));
            // Csak akkor írjuk felül a titkot, ha új értéket adtak meg.
            if ($val !== '') {
                $this->settings->set($officeId, $key, $val);
            }
        }

        $this->audit->log('settings.update');
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'A beállítások elmentve.'];

        return $this->redirect($response, '/admin/beallitasok');
    }

    /** @return array<string,mixed>|null */
    private function flash(): ?array
    {
        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return is_array($f) ? $f : null;
    }

    private function redirect(Response $response, string $to): Response
    {
        return $response->withHeader('Location', $to)->withStatus(302);
    }
}
