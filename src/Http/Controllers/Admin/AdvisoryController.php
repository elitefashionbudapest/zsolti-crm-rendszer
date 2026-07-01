<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Advisory\AdvisoryRepository;
use App\Auth\Auth;
use App\Clients\ClientRepository;
use App\Support\AuditLogger;
use App\Support\HtmlSanitizer;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Tanácsadói anyagok kezelése: lista, létrehozás, szerkesztés, törlés —
 * tenant-tudatosan. Az anyag szólhat egy adott ügyfélnek vagy mindenkinek.
 */
final class AdvisoryController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private AdvisoryRepository $advisory,
        private ClientRepository $clients,
        private AuditLogger $audit,
        private HtmlSanitizer $sanitizer,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/advisory/index.twig', [
            'active' => 'settings',
            'rows' => $this->advisory->listAll(),
            'clientNames' => $this->clientNames(),
            'flash' => $this->flash(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/advisory/form.twig', [
            'active' => 'settings',
            'item' => $this->blank(),
            'clients' => $this->clientOptions(),
            'errors' => [],
            'mode' => 'create',
            'action' => '/admin/tanacsadas',
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $this->extract($request);
        $errors = $this->validate($data);
        if ($errors !== []) {
            return $this->twig->render($response->withStatus(422), 'admin/advisory/form.twig', [
                'active' => 'settings',
                'item' => $data,
                'clients' => $this->clientOptions(),
                'errors' => $errors,
                'mode' => 'create',
                'action' => '/admin/tanacsadas',
            ]);
        }

        $id = $this->advisory->create($data);
        $this->audit->log('advisory.create', 'advisory', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Anyag létrehozva.'];

        return $this->redirect($response, '/admin/tanacsadas');
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $item = $this->advisory->find((int) $args['id']);
        if ($item === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'admin/advisory/form.twig', [
            'active' => 'settings',
            'item' => $item,
            'clients' => $this->clientOptions(),
            'errors' => [],
            'mode' => 'edit',
            'action' => '/admin/tanacsadas/' . $item['id'],
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->advisory->find($id) === null) {
            return $response->withStatus(404);
        }

        $data = $this->extract($request);
        $errors = $this->validate($data);
        if ($errors !== []) {
            $data['id'] = $id;
            return $this->twig->render($response->withStatus(422), 'admin/advisory/form.twig', [
                'active' => 'settings',
                'item' => $data,
                'clients' => $this->clientOptions(),
                'errors' => $errors,
                'mode' => 'edit',
                'action' => '/admin/tanacsadas/' . $id,
            ]);
        }

        $this->advisory->update($id, $data);
        $this->audit->log('advisory.update', 'advisory', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Anyag frissítve.'];

        return $this->redirect($response, '/admin/tanacsadas');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->advisory->find($id) !== null) {
            $this->advisory->delete($id);
            $this->audit->log('advisory.delete', 'advisory', $id);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Anyag törölve.'];
        }

        return $this->redirect($response, '/admin/tanacsadas');
    }

    /** @return array<string,mixed> */
    private function extract(Request $request): array
    {
        $body = (array) $request->getParsedBody();

        $title = trim((string) ($body['title'] ?? ''));
        $bodyText = trim((string) ($body['body'] ?? ''));
        $clientId = trim((string) ($body['client_id'] ?? ''));

        return [
            'title' => $title === '' ? null : $title,
            'body' => $bodyText === '' ? null : $this->sanitizer->clean($bodyText),
            'client_id' => $clientId === '' ? null : (int) $clientId,
            'is_published' => isset($body['is_published']) ? 1 : 0,
        ];
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
        if ($data['client_id'] !== null && !isset($this->clientNames()[(int) $data['client_id']])) {
            $errors['client_id'] = 'Érvénytelen ügyfél.';
        }

        return $errors;
    }

    /** @return array<string,mixed> */
    private function blank(): array
    {
        return [
            'id' => null,
            'title' => null,
            'body' => null,
            'client_id' => null,
            'is_published' => 1,
        ];
    }

    /**
     * Az iroda ügyfeleinek listája a legördülő mezőhöz.
     *
     * @return array<int,array{id:int,name:string}>
     */
    private function clientOptions(): array
    {
        $result = $this->clients->paginate('', '', 1, 1000);
        $options = [];
        foreach ($result['rows'] as $row) {
            $options[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }

        return $options;
    }

    /**
     * Ügyfél azonosító => név leképezés (listához és validáláshoz).
     *
     * @return array<int,string>
     */
    private function clientNames(): array
    {
        $names = [];
        foreach ($this->clientOptions() as $opt) {
            $names[$opt['id']] = $opt['name'];
        }

        return $names;
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
