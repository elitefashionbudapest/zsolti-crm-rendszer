<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Advisory\AdvisoryRepository;
use App\Ai\ClaudeClient;
use App\Auth\Auth;
use App\Settings\SettingsService;
use App\Support\AuditLogger;
use PDO;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Tanácsadói anyag AI-szerkesztője: brief + arculat + kizárandók → Claude
 * generál HTML-t → vizuális (WYSIWYG) szerkesztőbe kerül → szerkesztés → mentés/publikálás.
 */
final class AdvisoryAiController
{
    private const TONES = [
        'szakmai' => 'szakmai, tárgyilagos',
        'baratsagos' => 'barátságos, közvetlen',
        'meggyozo' => 'meggyőző, értékesítés-orientált',
        'egyszeru' => 'egyszerű, közérthető',
    ];
    private const LENGTHS = [
        'rovid' => 'rövid (kb. 200 szó)',
        'kozepes' => 'közepes (kb. 400 szó)',
        'hosszu' => 'hosszú (kb. 700 szó)',
    ];

    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private AdvisoryRepository $advisory,
        private ClaudeClient $claude,
        private SettingsService $settings,
        private PDO $pdo,
        private AuditLogger $audit,
    ) {
    }

    public function editor(Request $request, Response $response): Response
    {
        $id = (int) (((array) $request->getQueryParams())['id'] ?? 0);
        $resource = $id > 0 ? $this->advisory->find($id) : null;

        return $this->twig->render($response, 'admin/advisory/editor.twig', [
            'active' => 'advisory',
            'resource' => $resource,
            'clients' => $this->clientsForOffice(),
            'tones' => self::TONES,
            'lengths' => self::LENGTHS,
        ]);
    }

    public function generate(Request $request, Response $response): Response
    {
        $b = (array) $request->getParsedBody();
        $brief = trim((string) ($b['brief'] ?? ''));
        if ($brief === '') {
            return $this->json($response, ['ok' => false, 'error' => 'Add meg, miről generáljon szöveget.']);
        }

        $officeId = (int) ($this->auth->officeId() ?? 0);
        $apiKey = (string) $this->settings->get($officeId, 'anthropic_api_key', '');
        if ($apiKey === '') {
            return $this->json($response, ['ok' => false, 'error' => 'Állítsd be az Anthropic API kulcsot a Beállításoknál.']);
        }
        $model = (string) $this->settings->get($officeId, 'anthropic_model', 'claude-opus-4-8');

        $tone = self::TONES[(string) ($b['tone'] ?? 'szakmai')] ?? 'szakmai';
        $length = self::LENGTHS[(string) ($b['length'] ?? 'kozepes')] ?? 'közepes';
        $audience = trim((string) ($b['audience'] ?? ''));
        $exclude = trim((string) ($b['exclude'] ?? ''));
        $accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($b['accent'] ?? '')) ? (string) $b['accent'] : '#0F2A4A';

        $instruction = "Profi frontend-dizájnerként készíts egy IGÉNYESEN MEGTERVEZETT, vizuálisan vonzó "
            . "tanácsadói tájékoztató anyagot MAGYARUL a következő témáról:\n\"$brief\".\n"
            . "Hangnem: $tone. Hosszúság: $length.\n"
            . ($audience !== '' ? "Célközönség: $audience.\n" : '')
            . ($exclude !== '' ? "FONTOS — kerüld, és semmiképp NE említsd a következőket: $exclude.\n" : '')
            . "\nDIZÁJN ÉS FORMÁTUM (KÖTELEZŐ):\n"
            . "- Adj vissza EGYETLEN önálló HTML blokkot, KIZÁRÓLAG inline CSS stílusokkal (style=\"...\"). "
            . "NE használj <style> taget, CSS-osztályt, sem <html>/<head>/<body> taget, sem kódkeretet (```).\n"
            . "- Csomagold az egészet egy konténerbe: "
            . "<div style=\"max-width:760px;margin:0 auto;font-family:Inter,system-ui,sans-serif;color:#1E293B;line-height:1.7;\"> … </div>\n"
            . "- A legtetejére tegyél egy kis 'eyebrow' címkét: kis méret, NAGYBETŰ, betűköz, színe $accent.\n"
            . "- Főcím <h2> (kb. 28px, félkövér, színe $accent), alatta egy bevezető (lead) bekezdés kicsit nagyobb, halványabb (#64748B) szöveggel.\n"
            . "- Tagold alcímekkel: <h3> (félkövér, színe $accent, fölötte nagyobb térköz). Bekezdések 16px, kényelmes sortávval.\n"
            . "- Legalább egy listát formázz szépen (<ul> egyedi térközökkel).\n"
            . "- SIGNATURE elem: egy kiemelő 'Jó, ha tudja' / 'Kulcs tippek' doboz — háttér #F5F7FA, bal oldali 4px-es szegély $accent színnel, lekerekített sarok (12px), belső térköz, benne 3–4 rövid, hasznos pont.\n"
            . "- A végén egy rövid, bátorító záró mondat (NEM tukmáló).\n"
            . "- Bőséges térköz a szakaszok közt (margin), átgondolt tipográfiai hierarchia. Legyen egyedi és igényes, ne sablonos.\n"
            . "- Tényszerű, korrekt, közérthető tartalom, hibátlan magyar helyesírással. Csak a kész HTML-t add vissza.";

        try {
            $html = $this->claude->complete($instruction, $apiKey, $model);
            return $this->json($response, ['ok' => true, 'html' => $html]);
        } catch (Throwable $e) {
            return $this->json($response, ['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    public function save(Request $request, Response $response): Response
    {
        $b = (array) $request->getParsedBody();
        $id = (int) ($b['id'] ?? 0);
        $title = trim((string) ($b['title'] ?? ''));
        $body = (string) ($b['body'] ?? '');
        $clientId = (int) ($b['client_id'] ?? 0);
        $published = !empty($b['is_published']) ? 1 : 0;

        if ($title === '') {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'A cím megadása kötelező.'];
            return $this->redirect($response, '/admin/tanacsadas/szerkeszto' . ($id > 0 ? '?id=' . $id : ''));
        }

        $data = [
            'title' => $title,
            'body' => $body,
            'client_id' => $clientId > 0 ? $clientId : null,
            'is_published' => $published,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($id > 0 && $this->advisory->find($id) !== null) {
            $this->advisory->update($id, $data);
            $this->audit->log('advisory.update', 'advisory', $id);
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $id = $this->advisory->create($data);
            $this->audit->log('advisory.create', 'advisory', $id);
        }

        $_SESSION['flash'] = ['type' => 'success', 'msg' => $published ? 'Anyag mentve és publikálva.' : 'Anyag piszkozatként mentve.'];

        return $this->redirect($response, '/admin/tanacsadas');
    }

    /** @return list<array{id:int,name:string}> */
    private function clientsForOffice(): array
    {
        $officeId = (int) ($this->auth->officeId() ?? 0);
        $stmt = $this->pdo->prepare('SELECT id, name FROM clients WHERE office_id = :o ORDER BY name ASC');
        $stmt->execute(['o' => $officeId]);

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $stmt->fetchAll());
    }

    private function json(Response $response, array $data): Response
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private function redirect(Response $response, string $to): Response
    {
        return $response->withHeader('Location', $to)->withStatus(302);
    }
}
