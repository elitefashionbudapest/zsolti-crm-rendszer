<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\Auth;
use App\Clients\ClientAttributeRepository;
use App\Contracts\ContractRepository;
use App\Support\AuditLogger;
use PDO;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Szerződések kezelése: lista (keresés/szűrés), megtekintés, létrehozás,
 * szerkesztés, törlés — tenant-tudatosan.
 */
final class ContractsController
{
    /** A kategória-választó értékei és magyar címkéi. */
    private const CATEGORIES = [
        'elet_egeszseg' => 'Élet- és egészségbiztosítás',
        'vagyon' => 'Vagyonbiztosítás',
        'nyugdij_megtakaritas' => 'Nyugdíj és megtakarítás',
        'befektetes' => 'Befektetés / pénzügyi terv',
    ];

    private const FIELDS = [
        'client_id', 'category', 'insurer_name', 'module_code', 'module_name',
        'policy_number', 'offer_number', 'start_date', 'end_date', 'anniversary',
        'plate', 'annual_fee', 'status', 'terminated_reason', 'agent_code',
        'agent_name', 'payment_frequency', 'payment_method', 'risk_location',
    ];

    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private ContractRepository $contracts,
        private ClientAttributeRepository $attributes,
        private AuditLogger $audit,
        private PDO $pdo,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $q = (array) $request->getQueryParams();
        $search = trim((string) ($q['q'] ?? ''));
        $category = (string) ($q['category'] ?? '');
        $status = (string) ($q['status'] ?? '');
        $page = max(1, (int) ($q['page'] ?? 1));

        $result = $this->contracts->paginate($search, $category, $status, $page);

        return $this->twig->render($response, 'admin/contracts/index.twig', [
            'active' => 'contracts',
            'list' => $result,
            'search' => $search,
            'category' => $category,
            'status' => $status,
            'categories' => self::CATEGORIES,
            'flash' => $this->flash(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/contracts/form.twig', [
            'active' => 'contracts',
            'contract' => $this->blank(),
            'errors' => [],
            'mode' => 'create',
            'action' => '/admin/szerzodesek',
            'clients' => $this->contracts->clientsForOffice(),
            'categories' => self::CATEGORIES,
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $this->extract($request);
        $errors = $this->validate($data);
        if ($errors !== []) {
            return $this->twig->render($response->withStatus(422), 'admin/contracts/form.twig', [
                'active' => 'contracts', 'contract' => $data, 'errors' => $errors, 'mode' => 'create',
                'action' => '/admin/szerzodesek', 'clients' => $this->contracts->clientsForOffice(),
                'categories' => self::CATEGORIES,
            ]);
        }

        $id = $this->contracts->create($data);
        $this->audit->log('contract.create', 'contract', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Szerződés létrehozva.'];

        return $this->redirect($response, '/admin/szerzodesek/' . $id);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $contract = $this->contracts->find((int) $args['id']);
        if ($contract === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'admin/contracts/show.twig', [
            'active' => 'contracts',
            'contract' => $contract,
            'categories' => self::CATEGORIES,
            'attributes' => $this->groupAttributes($this->attributes->forContract((int) $contract['id'])),
            'flash' => $this->flash(),
        ]);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $contract = $this->contracts->find((int) $args['id']);
        if ($contract === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'admin/contracts/form.twig', [
            'active' => 'contracts',
            'contract' => $contract,
            'errors' => [],
            'mode' => 'edit',
            'action' => '/admin/szerzodesek/' . $contract['id'],
            'clients' => $this->contracts->clientsForOffice(),
            'categories' => self::CATEGORIES,
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->contracts->find($id) === null) {
            return $response->withStatus(404);
        }

        $data = $this->extract($request);
        $errors = $this->validate($data);
        if ($errors !== []) {
            $data['id'] = $id;
            return $this->twig->render($response->withStatus(422), 'admin/contracts/form.twig', [
                'active' => 'contracts', 'contract' => $data, 'errors' => $errors, 'mode' => 'edit',
                'action' => '/admin/szerzodesek/' . $id, 'clients' => $this->contracts->clientsForOffice(),
                'categories' => self::CATEGORIES,
            ]);
        }

        $this->contracts->update($id, $data);
        $this->audit->log('contract.update', 'contract', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Szerződés frissítve.'];

        return $this->redirect($response, '/admin/szerzodesek/' . $id);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->contracts->find($id) !== null) {
            $this->contracts->delete($id);
            $this->audit->log('contract.delete', 'contract', $id);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Szerződés törölve.'];
        }

        return $this->redirect($response, '/admin/szerzodesek');
    }

    /** Új, kézi attribútum a szerződéshez. */
    public function addAttribute(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $contract = $this->contracts->find($id);
        if ($contract === null) {
            return $response->withStatus(404);
        }

        $body = (array) $request->getParsedBody();
        $label = trim((string) ($body['label'] ?? ''));
        $value = trim((string) ($body['value'] ?? ''));
        $group = trim((string) ($body['group'] ?? 'egyeb')) ?: 'egyeb';
        $key = trim((string) ($body['attr_key'] ?? ''));
        if ($key === '') {
            $key = $this->slugKey($label);
        }

        if ($label === '' || $value === '' || $key === '') {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'A megnevezés és az érték megadása kötelező.'];

            return $this->redirect($response, '/admin/szerzodesek/' . $id . '#attributumok');
        }

        $this->attributes->addManual((int) $contract['client_id'], $group, $key, $label, $value, $id);
        $this->audit->log('contract.attr.add', 'contract', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Adat hozzáadva.'];

        return $this->redirect($response, '/admin/szerzodesek/' . $id . '#attributumok');
    }

    /** Egy szerződés-attribútum frissítése (felirat + érték). */
    public function updateAttribute(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->contracts->find($id) === null) {
            return $response->withStatus(404);
        }

        $body = (array) $request->getParsedBody();
        $label = trim((string) ($body['label'] ?? ''));
        $value = trim((string) ($body['value'] ?? ''));

        if ($label === '') {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'A megnevezés nem lehet üres.'];

            return $this->redirect($response, '/admin/szerzodesek/' . $id . '#attributumok');
        }

        $this->attributes->updateOne((int) $args['attrId'], $label, $value);
        $this->audit->log('contract.attr.update', 'contract', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Adat frissítve.'];

        return $this->redirect($response, '/admin/szerzodesek/' . $id . '#attributumok');
    }

    /** Egy szerződés-attribútum törlése. */
    public function deleteAttribute(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->contracts->find($id) === null) {
            return $response->withStatus(404);
        }

        $this->attributes->delete((int) $args['attrId']);
        $this->audit->log('contract.attr.delete', 'contract', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Adat törölve.'];

        return $this->redirect($response, '/admin/szerzodesek/' . $id . '#attributumok');
    }

    /**
     * Az attribútumsorok csoport szerinti tömbösítése a megjelenítéshez.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function groupAttributes(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) ($row['attr_group'] ?? 'egyeb')][] = $row;
        }

        return $grouped;
    }

    /** Magyar feliratból stabil, ékezet nélküli snake_case kulcs. */
    private function slugKey(string $label): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $label);
        $ascii = $ascii !== false ? $ascii : $label;
        $ascii = strtolower($ascii);
        $ascii = preg_replace('/[^a-z0-9]+/', '_', $ascii) ?? '';

        return trim($ascii, '_');
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
        $data['client_id'] = $data['client_id'] !== null ? (int) $data['client_id'] : null;
        if ($data['status'] === null) {
            $data['status'] = 'active';
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

        if ($data['client_id'] === null || $data['client_id'] <= 0) {
            $errors['client_id'] = 'Az ügyfél megadása kötelező.';
        } elseif (!$this->contracts->clientBelongsToOffice((int) $data['client_id'])) {
            $errors['client_id'] = 'Érvénytelen ügyfél.';
        }

        if ($data['category'] === null || !array_key_exists((string) $data['category'], self::CATEGORIES)) {
            $errors['category'] = 'A kategória megadása kötelező.';
        }

        if ($data['start_date'] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data['start_date'])) {
            $errors['start_date'] = 'A dátum formátuma ÉÉÉÉ-HH-NN.';
        }
        if ($data['end_date'] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data['end_date'])) {
            $errors['end_date'] = 'A dátum formátuma ÉÉÉÉ-HH-NN.';
        }

        return $errors;
    }

    /** @return array<string,mixed> */
    private function blank(): array
    {
        $b = array_fill_keys(self::FIELDS, null);
        $b['status'] = 'active';

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
