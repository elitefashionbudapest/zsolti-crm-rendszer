<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\Auth;
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
