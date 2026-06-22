<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\Auth;
use App\Commissions\CommissionRepository;
use App\Support\AuditLogger;
use PDO;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Jutalékok kezelése: lista (státusz-szűrés, lapozás, összesítők), létrehozás,
 * szerkesztés, törlés és rendezés — tenant-tudatosan.
 */
final class CommissionsController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private CommissionRepository $commissions,
        private AuditLogger $audit,
        private PDO $pdo,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $q = (array) $request->getQueryParams();
        $status = (string) ($q['status'] ?? '');
        $page = max(1, (int) ($q['page'] ?? 1));

        $result = $this->commissions->paginate($status, $page);

        return $this->twig->render($response, 'admin/commissions/index.twig', [
            'active' => 'commissions',
            'list' => $result,
            'status' => $status,
            'totals' => $this->commissions->totals(),
            'flash' => $this->flash(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/commissions/form.twig', [
            'active' => 'commissions',
            'commission' => $this->blank(),
            'contracts' => $this->commissions->contractsForOffice(),
            'errors' => [],
            'mode' => 'create',
            'action' => '/admin/jutalekok',
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $this->extract($request);
        $errors = $this->validate($data);
        if ($errors !== []) {
            return $this->twig->render($response->withStatus(422), 'admin/commissions/form.twig', [
                'active' => 'commissions',
                'commission' => $data,
                'contracts' => $this->commissions->contractsForOffice(),
                'errors' => $errors,
                'mode' => 'create',
                'action' => '/admin/jutalekok',
            ]);
        }

        $data['user_id'] = $this->auth->id();
        $id = $this->commissions->create($data);
        $this->audit->log('commission.create', 'commission', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Jutalék létrehozva.'];

        return $this->redirect($response, '/admin/jutalekok');
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $commission = $this->commissions->find((int) $args['id']);
        if ($commission === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'admin/commissions/form.twig', [
            'active' => 'commissions',
            'commission' => $commission,
            'contracts' => $this->commissions->contractsForOffice(),
            'errors' => [],
            'mode' => 'edit',
            'action' => '/admin/jutalekok/' . $commission['id'],
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->commissions->find($id) === null) {
            return $response->withStatus(404);
        }

        $data = $this->extract($request);
        $errors = $this->validate($data);
        if ($errors !== []) {
            $data['id'] = $id;
            return $this->twig->render($response->withStatus(422), 'admin/commissions/form.twig', [
                'active' => 'commissions',
                'commission' => $data,
                'contracts' => $this->commissions->contractsForOffice(),
                'errors' => $errors,
                'mode' => 'edit',
                'action' => '/admin/jutalekok/' . $id,
            ]);
        }

        $this->commissions->update($id, $data);
        $this->audit->log('commission.update', 'commission', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Jutalék frissítve.'];

        return $this->redirect($response, '/admin/jutalekok');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->commissions->find($id) !== null) {
            $this->commissions->delete($id);
            $this->audit->log('commission.delete', 'commission', $id);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Jutalék törölve.'];
        }

        return $this->redirect($response, '/admin/jutalekok');
    }

    public function settle(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->commissions->find($id) !== null) {
            $this->commissions->markSettled($id);
            $this->audit->log('commission.settle', 'commission', $id);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Jutalék rendezve.'];
        }

        return $this->redirect($response, '/admin/jutalekok');
    }

    /** @return array<string,mixed> */
    private function extract(Request $request): array
    {
        $body = (array) $request->getParsedBody();

        $contractId = trim((string) ($body['contract_id'] ?? ''));
        $amount = trim((string) ($body['amount'] ?? ''));
        $status = trim((string) ($body['status'] ?? ''));
        $settledAt = trim((string) ($body['settled_at'] ?? ''));

        return [
            'contract_id' => $contractId === '' ? null : (int) $contractId,
            'amount' => $amount === '' ? null : str_replace([' ', ','], ['', '.'], $amount),
            'status' => $status === 'settled' ? 'settled' : 'pending',
            'settled_at' => $settledAt === '' ? null : $settledAt,
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private function validate(array $data): array
    {
        $errors = [];
        if ($data['amount'] === null || !is_numeric($data['amount'])) {
            $errors['amount'] = 'Az összeg megadása kötelező, és számnak kell lennie.';
        }
        if ($data['settled_at'] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data['settled_at'])) {
            $errors['settled_at'] = 'A dátum formátuma ÉÉÉÉ-HH-NN.';
        }

        return $errors;
    }

    /** @return array<string,mixed> */
    private function blank(): array
    {
        return [
            'id' => null,
            'contract_id' => null,
            'amount' => null,
            'status' => 'pending',
            'settled_at' => null,
        ];
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
