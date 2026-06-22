<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\Auth;
use App\Support\AuditLogger;
use App\Tasks\TaskRepository;
use PDO;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Feladatok kezelése: lista (keresés/szűrés), megtekintés, létrehozás,
 * szerkesztés, törlés, státuszváltás — tenant-tudatosan.
 */
final class TasksController
{
    private const PRIORITIES = ['low', 'normal', 'high'];
    private const STATUSES = ['open', 'done'];

    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private TaskRepository $tasks,
        private AuditLogger $audit,
        private PDO $pdo,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $q = (array) $request->getQueryParams();
        $search = trim((string) ($q['q'] ?? ''));
        $status = (string) ($q['status'] ?? '');
        if (!in_array($status, self::STATUSES, true)) {
            $status = '';
        }
        $page = max(1, (int) ($q['page'] ?? 1));

        $result = $this->tasks->paginate($search, $status, $page);

        return $this->twig->render($response, 'admin/tasks/index.twig', [
            'active' => 'tasks',
            'list' => $result,
            'search' => $search,
            'status' => $status,
            'flash' => $this->flash(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/tasks/form.twig', [
            'active' => 'tasks',
            'task' => $this->blank(),
            'clients' => $this->tasks->clientsForOffice(),
            'errors' => [],
            'mode' => 'create',
            'action' => '/admin/feladatok',
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $this->extract($request);
        $errors = $this->validate($data);
        if ($errors !== []) {
            return $this->twig->render($response->withStatus(422), 'admin/tasks/form.twig', [
                'active' => 'tasks',
                'task' => $data,
                'clients' => $this->tasks->clientsForOffice(),
                'errors' => $errors,
                'mode' => 'create',
                'action' => '/admin/feladatok',
            ]);
        }

        $data['assigned_to'] = $this->auth->id();
        $id = $this->tasks->create($data);
        $this->audit->log('task.create', 'task', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Feladat létrehozva.'];

        return $this->redirect($response, '/admin/feladatok/' . $id);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $task = $this->tasks->find((int) $args['id']);
        if ($task === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'admin/tasks/show.twig', [
            'active' => 'tasks',
            'task' => $task,
            'flash' => $this->flash(),
        ]);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $task = $this->tasks->find((int) $args['id']);
        if ($task === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'admin/tasks/form.twig', [
            'active' => 'tasks',
            'task' => $task,
            'clients' => $this->tasks->clientsForOffice(),
            'errors' => [],
            'mode' => 'edit',
            'action' => '/admin/feladatok/' . $task['id'],
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->tasks->find($id) === null) {
            return $response->withStatus(404);
        }

        $data = $this->extract($request);
        $errors = $this->validate($data);
        if ($errors !== []) {
            $data['id'] = $id;
            return $this->twig->render($response->withStatus(422), 'admin/tasks/form.twig', [
                'active' => 'tasks',
                'task' => $data,
                'clients' => $this->tasks->clientsForOffice(),
                'errors' => $errors,
                'mode' => 'edit',
                'action' => '/admin/feladatok/' . $id,
            ]);
        }

        $this->tasks->update($id, $data);
        $this->audit->log('task.update', 'task', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Feladat frissítve.'];

        return $this->redirect($response, '/admin/feladatok/' . $id);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->tasks->find($id) !== null) {
            $this->tasks->delete($id);
            $this->audit->log('task.delete', 'task', $id);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Feladat törölve.'];
        }

        return $this->redirect($response, '/admin/feladatok');
    }

    public function toggle(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->tasks->find($id) !== null) {
            $this->tasks->toggleStatus($id);
            $this->audit->log('task.update', 'task', $id);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Feladat státusza frissítve.'];
        }

        return $this->redirect($response, '/admin/feladatok');
    }

    /** @return array<string,mixed> */
    private function extract(Request $request): array
    {
        $body = (array) $request->getParsedBody();

        $title = trim((string) ($body['title'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));

        $clientId = trim((string) ($body['client_id'] ?? ''));
        $priority = (string) ($body['priority'] ?? 'normal');
        if (!in_array($priority, self::PRIORITIES, true)) {
            $priority = 'normal';
        }
        $status = (string) ($body['status'] ?? 'open');
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'open';
        }

        return [
            'title' => $title === '' ? null : $title,
            'description' => $description === '' ? null : $description,
            'client_id' => $clientId === '' ? null : (int) $clientId,
            'due_at' => $this->normalizeDueAt((string) ($body['due_at'] ?? '')),
            'priority' => $priority,
            'status' => $status,
        ];
    }

    /**
     * A datetime-local mező 'YYYY-MM-DDTHH:MM' formátumát normalizálja
     * 'YYYY-MM-DD HH:MM:SS' alakra, üres bemenetnél null.
     */
    private function normalizeDueAt(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(?::\d{2})?$/', $raw, $m) === 1) {
            return $m[1] . ' ' . $m[2] . ':00';
        }

        return null;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private function validate(array $data): array
    {
        $errors = [];
        if ($data['title'] === null || mb_strlen((string) $data['title']) < 2) {
            $errors['title'] = 'A cím megadása kötelező (legalább 2 karakter).';
        }

        return $errors;
    }

    /** @return array<string,mixed> */
    private function blank(): array
    {
        return [
            'title' => null,
            'description' => null,
            'client_id' => null,
            'due_at' => null,
            'priority' => 'normal',
            'status' => 'open',
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
