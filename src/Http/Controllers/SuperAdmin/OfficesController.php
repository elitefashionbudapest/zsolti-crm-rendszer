<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Support\AuditLogger;
use PDO;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Irodák globális kezelése a szuperadmin felületen.
 * A super_admin minden irodát lát — közvetlen PDO, nincs tenant-szűrés.
 */
final class OfficesController
{
    public function __construct(
        private Twig $twig,
        private PDO $pdo,
        private AuditLogger $audit,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $stmt = $this->pdo->query(
            'SELECT o.id, o.name, o.slug, o.is_active, o.created_at,
                    (SELECT COUNT(*) FROM users u WHERE u.office_id = o.id) AS user_count
             FROM offices o
             ORDER BY o.name ASC'
        );
        $offices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->twig->render($response, 'superadmin/offices/index.twig', [
            'offices' => $offices,
            'flash' => $this->flash(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'superadmin/offices/form.twig', [
            'office' => $this->blank(),
            'errors' => [],
            'mode' => 'create',
            'action' => '/superadmin/irodak',
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $this->extract($request);
        $errors = $this->validate($data, null);
        if ($errors !== []) {
            return $this->twig->render($response->withStatus(422), 'superadmin/offices/form.twig', [
                'office' => $data, 'errors' => $errors, 'mode' => 'create', 'action' => '/superadmin/irodak',
            ]);
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO offices (name, slug, is_active, created_at, updated_at)
             VALUES (:name, :slug, :active, :c, :u)'
        );
        $stmt->execute([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'active' => $data['is_active'],
            'c' => $now,
            'u' => $now,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log('office.create', 'office', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Iroda létrehozva.'];

        return $this->redirect($response, '/superadmin/irodak');
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $office = $this->find((int) $args['id']);
        if ($office === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'superadmin/offices/form.twig', [
            'office' => $office,
            'errors' => [],
            'mode' => 'edit',
            'action' => '/superadmin/irodak/' . $office['id'],
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->find($id) === null) {
            return $response->withStatus(404);
        }

        $data = $this->extract($request);
        $errors = $this->validate($data, $id);
        if ($errors !== []) {
            $data['id'] = $id;
            return $this->twig->render($response->withStatus(422), 'superadmin/offices/form.twig', [
                'office' => $data, 'errors' => $errors, 'mode' => 'edit', 'action' => '/superadmin/irodak/' . $id,
            ]);
        }

        $stmt = $this->pdo->prepare(
            'UPDATE offices SET name = :name, slug = :slug, is_active = :active, updated_at = :u WHERE id = :id'
        );
        $stmt->execute([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'active' => $data['is_active'],
            'u' => date('Y-m-d H:i:s'),
            'id' => $id,
        ]);
        $this->audit->log('office.update', 'office', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Iroda frissítve.'];

        return $this->redirect($response, '/superadmin/irodak');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->find($id) !== null) {
            // A kapcsolódó rekordok törlését az FK ON DELETE CASCADE intézi.
            $stmt = $this->pdo->prepare('DELETE FROM offices WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $this->audit->log('office.delete', 'office', $id);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Iroda törölve.'];
        }

        return $this->redirect($response, '/superadmin/irodak');
    }

    /** @return array<string,mixed>|null */
    private function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM offices WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed> */
    private function extract(Request $request): array
    {
        $body = (array) $request->getParsedBody();
        $name = trim((string) ($body['name'] ?? ''));
        $slug = trim((string) ($body['slug'] ?? ''));
        if ($slug === '' && $name !== '') {
            $slug = $this->slugify($name);
        }

        return [
            'name' => $name,
            'slug' => $slug === '' ? null : $this->slugify($slug),
            'is_active' => isset($body['is_active']) && (string) $body['is_active'] !== '0' ? 1 : 0,
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private function validate(array $data, ?int $ignoreId): array
    {
        $errors = [];
        if ($data['name'] === null || mb_strlen((string) $data['name']) < 2) {
            $errors['name'] = 'Az iroda nevének megadása kötelező (legalább 2 karakter).';
        }
        if ($data['slug'] !== null) {
            $sql = 'SELECT COUNT(*) AS c FROM offices WHERE slug = :slug';
            $params = ['slug' => $data['slug']];
            if ($ignoreId !== null) {
                $sql .= ' AND id <> :id';
                $params['id'] = $ignoreId;
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            if ((int) ($stmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0) > 0) {
                $errors['slug'] = 'Ez az URL-azonosító (slug) már foglalt.';
            }
        }

        return $errors;
    }

    private function slugify(string $value): string
    {
        $map = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u'];
        $value = strtr(mb_strtolower($value), $map);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($value, '-');
    }

    /** @return array<string,mixed> */
    private function blank(): array
    {
        return ['id' => null, 'name' => null, 'slug' => null, 'is_active' => 1];
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
