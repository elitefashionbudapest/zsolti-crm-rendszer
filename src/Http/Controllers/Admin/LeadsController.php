<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\Auth;
use App\Clients\ClientRepository;
use App\Leads\LeadRepository;
use App\Support\AuditLogger;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Leadek (értékesítési pipeline) kezelése: lista (keresés/fázis-szűrés), megtekintés,
 * létrehozás, szerkesztés, törlés és ügyféllé alakítás — tenant-tudatosan.
 */
final class LeadsController
{
    private const FIELDS = ['name', 'email', 'phone', 'source', 'stage', 'assigned_to', 'notes'];

    /** @var array<string,string> */
    private const STAGES = [
        'new' => 'Új',
        'contacted' => 'Megkeresve',
        'offer' => 'Ajánlat',
        'won' => 'Megnyert',
        'lost' => 'Elvesztett',
    ];

    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private LeadRepository $leads,
        private AuditLogger $audit,
        private ClientRepository $clients,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $q = (array) $request->getQueryParams();
        $search = trim((string) ($q['q'] ?? ''));
        $stage = (string) ($q['stage'] ?? '');
        if (!array_key_exists($stage, self::STAGES)) {
            $stage = '';
        }
        $page = max(1, (int) ($q['page'] ?? 1));

        $result = $this->leads->paginate($search, $stage, $page);

        return $this->twig->render($response, 'admin/leads/index.twig', [
            'active' => 'leads',
            'list' => $result,
            'search' => $search,
            'stage' => $stage,
            'stages' => self::STAGES,
            'flash' => $this->flash(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/leads/form.twig', [
            'active' => 'leads',
            'lead' => $this->blank(),
            'errors' => [],
            'stages' => self::STAGES,
            'mode' => 'create',
            'action' => '/admin/leadek',
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $this->extract($request);
        $errors = $this->validate($data);
        if ($errors !== []) {
            return $this->twig->render($response->withStatus(422), 'admin/leads/form.twig', [
                'active' => 'leads', 'lead' => $data, 'errors' => $errors, 'stages' => self::STAGES, 'mode' => 'create', 'action' => '/admin/leadek',
            ]);
        }

        $id = $this->leads->create($data);
        $this->audit->log('lead.create', 'lead', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Lead létrehozva.'];

        return $this->redirect($response, '/admin/leadek/' . $id);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $lead = $this->leads->find((int) $args['id']);
        if ($lead === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'admin/leads/show.twig', [
            'active' => 'leads',
            'lead' => $lead,
            'stages' => self::STAGES,
            'flash' => $this->flash(),
        ]);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $lead = $this->leads->find((int) $args['id']);
        if ($lead === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'admin/leads/form.twig', [
            'active' => 'leads',
            'lead' => $lead,
            'errors' => [],
            'stages' => self::STAGES,
            'mode' => 'edit',
            'action' => '/admin/leadek/' . $lead['id'],
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->leads->find($id) === null) {
            return $response->withStatus(404);
        }

        $data = $this->extract($request);
        $errors = $this->validate($data);
        if ($errors !== []) {
            $data['id'] = $id;
            return $this->twig->render($response->withStatus(422), 'admin/leads/form.twig', [
                'active' => 'leads', 'lead' => $data, 'errors' => $errors, 'stages' => self::STAGES, 'mode' => 'edit', 'action' => '/admin/leadek/' . $id,
            ]);
        }

        $this->leads->update($id, $data);
        $this->audit->log('lead.update', 'lead', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Lead frissítve.'];

        return $this->redirect($response, '/admin/leadek/' . $id);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->leads->find($id) !== null) {
            $this->leads->delete($id);
            $this->audit->log('lead.delete', 'lead', $id);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Lead törölve.'];
        }

        return $this->redirect($response, '/admin/leadek');
    }

    /**
     * Lead ügyféllé (partnerré) alakítása: partner létrehozása a lead adataiból,
     * majd a lead fázisának 'won'-ra állítása.
     */
    public function convert(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $lead = $this->leads->find($id);
        if ($lead === null) {
            return $response->withStatus(404);
        }

        $newId = $this->clients->create([
            'name' => $lead['name'],
            'email' => $lead['email'],
            'phone' => $lead['phone'],
            'status' => 'active',
            'owner_user_id' => $this->auth->id(),
        ]);

        $this->leads->update($id, ['stage' => 'won']);
        $this->audit->log('lead.convert', 'lead', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'A lead sikeresen partnerré alakult.'];

        return $this->redirect($response, '/admin/partnerek/' . $newId);
    }

    /** @return array<string,mixed> */
    private function extract(Request $request): array
    {
        $body = (array) $request->getParsedBody();
        $data = [];
        foreach (self::FIELDS as $f) {
            $val = trim((string) ($body[$f] ?? ''));
            $data[$f] = $val === '' ? null : $val;
        }
        if ($data['stage'] === null || !array_key_exists($data['stage'], self::STAGES)) {
            $data['stage'] = 'new';
        }

        return $data;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private function validate(array $data): array
    {
        $errors = [];
        if ($data['name'] === null || mb_strlen((string) $data['name']) < 2) {
            $errors['name'] = 'A név megadása kötelező (legalább 2 karakter).';
        }
        if ($data['email'] !== null && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Érvénytelen e-mail cím.';
        }

        return $errors;
    }

    /** @return array<string,mixed> */
    private function blank(): array
    {
        $b = array_fill_keys(self::FIELDS, null);
        $b['stage'] = 'new';

        return $b;
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
