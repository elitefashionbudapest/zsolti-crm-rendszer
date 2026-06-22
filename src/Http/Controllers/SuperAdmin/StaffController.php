<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Support\AuditLogger;
use PDO;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Irodai dolgozók (owner / assistant) globális kezelése a szuperadmin felületen.
 * Közvetlen PDO, nincs tenant-szűrés. Ügyfél- és szuperadmin-felhasználók itt nem szerkeszthetők.
 */
final class StaffController
{
    private const STAFF_ROLES = ['owner', 'assistant'];

    public function __construct(
        private Twig $twig,
        private PDO $pdo,
        private AuditLogger $audit,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $q = (array) $request->getQueryParams();
        $officeId = isset($q['office_id']) && $q['office_id'] !== '' ? (int) $q['office_id'] : null;

        $sql =
            "SELECT u.id, u.name, u.email, u.is_active, u.office_id, u.last_login_at,
                    o.name AS office_name,
                    GROUP_CONCAT(DISTINCT r.code) AS role_codes
             FROM users u
             INNER JOIN role_user ru ON ru.user_id = u.id
             INNER JOIN roles r ON r.id = ru.role_id
             LEFT JOIN offices o ON o.id = u.office_id
             WHERE u.id IN (
                 SELECT ru2.user_id FROM role_user ru2
                 INNER JOIN roles r2 ON r2.id = ru2.role_id
                 WHERE r2.code IN ('owner', 'assistant')
             )";
        $params = [];
        if ($officeId !== null) {
            $sql .= ' AND u.office_id = :oid';
            $params['oid'] = $officeId;
        }
        $sql .= ' GROUP BY u.id, u.name, u.email, u.is_active, u.office_id, u.last_login_at, o.name ORDER BY u.name ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->twig->render($response, 'superadmin/staff/index.twig', [
            'staff' => $staff,
            'offices' => $this->offices(),
            'officeId' => $officeId,
            'flash' => $this->flash(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'superadmin/staff/form.twig', [
            'user' => $this->blank(),
            'errors' => [],
            'mode' => 'create',
            'action' => '/superadmin/dolgozok',
            'offices' => $this->offices(),
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $this->extract($request);
        $errors = $this->validate($data, null, true);
        if ($errors !== []) {
            return $this->twig->render($response->withStatus(422), 'superadmin/staff/form.twig', [
                'user' => $data, 'errors' => $errors, 'mode' => 'create',
                'action' => '/superadmin/dolgozok', 'offices' => $this->offices(),
            ]);
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (office_id, name, email, password_hash, is_active, created_at, updated_at)
             VALUES (:office, :name, :email, :hash, 1, :c, :u)'
        );
        $stmt->execute([
            'office' => $data['office_id'],
            'name' => $data['name'],
            'email' => $data['email'],
            'hash' => password_hash((string) $data['password'], PASSWORD_DEFAULT),
            'c' => $now,
            'u' => $now,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->assignRole($id, (string) $data['role']);
        $this->audit->log('staff.create', 'user', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Dolgozó létrehozva.'];

        return $this->redirect($response, '/superadmin/dolgozok');
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $user = $this->findStaff((int) $args['id']);
        if ($user === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'superadmin/staff/form.twig', [
            'user' => $user,
            'errors' => [],
            'mode' => 'edit',
            'action' => '/superadmin/dolgozok/' . $user['id'],
            'offices' => $this->offices(),
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $existing = $this->findStaff($id);
        if ($existing === null) {
            return $response->withStatus(404);
        }

        $data = $this->extract($request);
        // Az e-mail nem szerkeszthető itt, a meglévőt visszahelyezzük az űrlap megjelenítéséhez.
        $data['email'] = $existing['email'];
        $errors = $this->validate($data, $id, false);
        if ($errors !== []) {
            $data['id'] = $id;
            return $this->twig->render($response->withStatus(422), 'superadmin/staff/form.twig', [
                'user' => $data, 'errors' => $errors, 'mode' => 'edit',
                'action' => '/superadmin/dolgozok/' . $id, 'offices' => $this->offices(),
            ]);
        }

        $sql = 'UPDATE users SET name = :name, office_id = :office, is_active = :active, updated_at = :u';
        $params = [
            'name' => $data['name'],
            'office' => $data['office_id'],
            'active' => $data['is_active'],
            'u' => date('Y-m-d H:i:s'),
            'id' => $id,
        ];
        if ($data['password'] !== null && $data['password'] !== '') {
            $sql .= ', password_hash = :hash';
            $params['hash'] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $this->setRole($id, (string) $data['role']);
        $this->audit->log('staff.update', 'user', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Dolgozó frissítve.'];

        return $this->redirect($response, '/superadmin/dolgozok');
    }

    public function deactivate(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $user = $this->findStaff($id);
        if ($user !== null) {
            $new = (int) $user['is_active'] === 1 ? 0 : 1;
            $stmt = $this->pdo->prepare('UPDATE users SET is_active = :a, updated_at = :u WHERE id = :id');
            $stmt->execute(['a' => $new, 'u' => date('Y-m-d H:i:s'), 'id' => $id]);
            $this->audit->log($new === 1 ? 'staff.activate' : 'staff.deactivate', 'user', $id);
            $_SESSION['flash'] = [
                'type' => 'success',
                'msg' => $new === 1 ? 'Dolgozó aktiválva.' : 'Dolgozó inaktiválva.',
            ];
        }

        return $this->redirect($response, '/superadmin/dolgozok');
    }

    /** Csak owner/assistant dolgozót ad vissza (ügyfél/szuperadmin nem szerkeszthető itt). */
    /** @return array<string,mixed>|null */
    private function findStaff(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.name, u.email, u.is_active, u.office_id,
                    (SELECT r.code FROM role_user ru INNER JOIN roles r ON r.id = ru.role_id
                     WHERE ru.user_id = u.id AND r.code IN ('owner', 'assistant') LIMIT 1) AS role
             FROM users u
             WHERE u.id = :id
               AND EXISTS (
                   SELECT 1 FROM role_user ru INNER JOIN roles r ON r.id = ru.role_id
                   WHERE ru.user_id = u.id AND r.code IN ('owner', 'assistant')
               )"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['password'] = null;

        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function offices(): array
    {
        $stmt = $this->pdo->query('SELECT id, name FROM offices ORDER BY name ASC');

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function assignRole(int $userId, string $code): void
    {
        $roleId = $this->roleId($code);
        if ($roleId === null) {
            return;
        }
        $stmt = $this->pdo->prepare('INSERT INTO role_user (user_id, role_id) VALUES (:u, :r)');
        $stmt->execute(['u' => $userId, 'r' => $roleId]);
    }

    /** Lecseréli a dolgozó staff-szerepkörét (owner/assistant), a többit érintetlenül hagyja. */
    private function setRole(int $userId, string $code): void
    {
        $del = $this->pdo->prepare(
            "DELETE FROM role_user WHERE user_id = :u AND role_id IN (
                 SELECT id FROM roles WHERE code IN ('owner', 'assistant')
             )"
        );
        $del->execute(['u' => $userId]);
        $this->assignRole($userId, $code);
    }

    private function roleId(string $code): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE code = :c');
        $stmt->execute(['c' => $code]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @return array<string,mixed> */
    private function extract(Request $request): array
    {
        $body = (array) $request->getParsedBody();
        $role = (string) ($body['role'] ?? '');

        return [
            'office_id' => isset($body['office_id']) && $body['office_id'] !== '' ? (int) $body['office_id'] : null,
            'name' => trim((string) ($body['name'] ?? '')),
            'email' => trim((string) ($body['email'] ?? '')),
            'password' => (string) ($body['password'] ?? ''),
            'role' => in_array($role, self::STAFF_ROLES, true) ? $role : '',
            'is_active' => isset($body['is_active']) && (string) $body['is_active'] !== '0' ? 1 : 0,
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private function validate(array $data, ?int $ignoreId, bool $requirePassword): array
    {
        $errors = [];
        if ($data['name'] === '' || mb_strlen((string) $data['name']) < 2) {
            $errors['name'] = 'A név megadása kötelező (legalább 2 karakter).';
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Érvénytelen e-mail cím.';
        } else {
            $sql = 'SELECT COUNT(*) AS c FROM users WHERE email = :email';
            $params = ['email' => $data['email']];
            if ($ignoreId !== null) {
                $sql .= ' AND id <> :id';
                $params['id'] = $ignoreId;
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            if ((int) ($stmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0) > 0) {
                $errors['email'] = 'Ez az e-mail cím már foglalt.';
            }
        }
        if ($data['role'] === '') {
            $errors['role'] = 'Válassz szerepkört (tulajdonos vagy asszisztens).';
        }
        $pw = (string) $data['password'];
        if ($requirePassword || $pw !== '') {
            if (mb_strlen($pw) < 8) {
                $errors['password'] = 'A jelszó legalább 8 karakter legyen.';
            }
        }

        return $errors;
    }

    /** @return array<string,mixed> */
    private function blank(): array
    {
        return [
            'id' => null, 'office_id' => null, 'name' => null, 'email' => null,
            'password' => null, 'role' => 'assistant', 'is_active' => 1,
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
