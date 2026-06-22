<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\Auth;
use App\Email\EmailTemplateRepository;
use App\Support\AuditLogger;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * E-mail sablonok kezelése (CRUD), tenant-tudatosan. A törzs HTML-t is tartalmazhat.
 */
final class EmailTemplatesController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private EmailTemplateRepository $templates,
        private AuditLogger $audit,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/email/templates_index.twig', [
            'active' => 'emails',
            'templates' => $this->templates->listAll(),
            'flash' => $this->flash(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/email/templates_form.twig', [
            'active' => 'emails',
            'template' => $this->blank(),
            'errors' => [],
            'mode' => 'create',
            'action' => '/admin/email-sablonok',
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $this->extract($request);
        $errors = $this->validate($data);
        if ($errors !== []) {
            return $this->twig->render($response->withStatus(422), 'admin/email/templates_form.twig', [
                'active' => 'emails', 'template' => $data, 'errors' => $errors, 'mode' => 'create',
                'action' => '/admin/email-sablonok',
            ]);
        }

        $id = $this->templates->create($data);
        $this->audit->log('email_template.create', 'email_template', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Sablon létrehozva.'];

        return $this->redirect($response, '/admin/email-sablonok');
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $template = $this->templates->find((int) $args['id']);
        if ($template === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'admin/email/templates_form.twig', [
            'active' => 'emails',
            'template' => $template,
            'errors' => [],
            'mode' => 'edit',
            'action' => '/admin/email-sablonok/' . $template['id'],
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->templates->find($id) === null) {
            return $response->withStatus(404);
        }

        $data = $this->extract($request);
        $errors = $this->validate($data);
        if ($errors !== []) {
            $data['id'] = $id;
            return $this->twig->render($response->withStatus(422), 'admin/email/templates_form.twig', [
                'active' => 'emails', 'template' => $data, 'errors' => $errors, 'mode' => 'edit',
                'action' => '/admin/email-sablonok/' . $id,
            ]);
        }

        $this->templates->update($id, $data);
        $this->audit->log('email_template.update', 'email_template', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Sablon frissítve.'];

        return $this->redirect($response, '/admin/email-sablonok');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->templates->find($id) !== null) {
            $this->templates->delete($id);
            $this->audit->log('email_template.delete', 'email_template', $id);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Sablon törölve.'];
        }

        return $this->redirect($response, '/admin/email-sablonok');
    }

    /** @return array<string,mixed> */
    private function extract(Request $request): array
    {
        $body = (array) $request->getParsedBody();

        return [
            'name' => trim((string) ($body['name'] ?? '')),
            'subject' => trim((string) ($body['subject'] ?? '')),
            'body' => (string) ($body['body'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private function validate(array $data): array
    {
        $errors = [];
        if ($data['name'] === '' || mb_strlen((string) $data['name']) < 2) {
            $errors['name'] = 'A sablon nevének megadása kötelező (legalább 2 karakter).';
        }
        if ($data['subject'] === '') {
            $errors['subject'] = 'A tárgy megadása kötelező.';
        }
        if (trim((string) $data['body']) === '') {
            $errors['body'] = 'A levél törzse nem lehet üres.';
        }

        return $errors;
    }

    /** @return array<string,mixed> */
    private function blank(): array
    {
        return ['name' => '', 'subject' => '', 'body' => ''];
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
