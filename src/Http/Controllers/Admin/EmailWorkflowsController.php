<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\Auth;
use App\Email\EmailSendRepository;
use App\Email\EmailTemplateRepository;
use App\Email\EmailWorkflowRepository;
use App\Mail\MailService;
use App\Support\AuditLogger;
use PDO;
use Slim\Views\Twig;
use Throwable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * E-mail folyamatok (automatizmusok) kezelése: CRUD és kézi futtatás. A "Futtatás
 * most" minden, e-mail címmel rendelkező ügyfélnek elküldi a folyamat sablonját.
 */
final class EmailWorkflowsController
{
    /** A trigger-típusok és magyar címkéik. */
    private const TRIGGER_TYPES = [
        'manual' => 'Kézi',
        'anniversary' => 'Évforduló',
        'expiry' => 'Lejárat',
        'welcome' => 'Üdvözlés',
        'newsletter' => 'Hírlevél',
    ];

    /** A célközönség-kulcsok és magyar címkéik. */
    private const AUDIENCES = [
        'all_clients' => 'Minden ügyfél',
    ];

    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private EmailWorkflowRepository $workflows,
        private EmailTemplateRepository $templates,
        private EmailSendRepository $sends,
        private MailService $mail,
        private AuditLogger $audit,
        private PDO $pdo,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/email/workflows_index.twig', [
            'active' => 'emails',
            'workflows' => $this->workflows->listAll(),
            'templates' => $this->templatesById(),
            'triggerTypes' => self::TRIGGER_TYPES,
            'flash' => $this->flash(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/email/workflows_form.twig', [
            'active' => 'emails',
            'workflow' => $this->blank(),
            'errors' => [],
            'mode' => 'create',
            'action' => '/admin/email-folyamatok',
            'templates' => $this->templates->listAll(),
            'triggerTypes' => self::TRIGGER_TYPES,
            'audiences' => self::AUDIENCES,
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $this->extract($request);
        $errors = $this->validate($data);
        if ($errors !== []) {
            return $this->formError($response, $data, $errors, 'create', '/admin/email-folyamatok');
        }

        $id = $this->workflows->create($data);
        $this->audit->log('email_workflow.create', 'email_workflow', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Folyamat létrehozva.'];

        return $this->redirect($response, '/admin/email-folyamatok');
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $workflow = $this->workflows->find((int) $args['id']);
        if ($workflow === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'admin/email/workflows_form.twig', [
            'active' => 'emails',
            'workflow' => $workflow,
            'errors' => [],
            'mode' => 'edit',
            'action' => '/admin/email-folyamatok/' . $workflow['id'],
            'templates' => $this->templates->listAll(),
            'triggerTypes' => self::TRIGGER_TYPES,
            'audiences' => self::AUDIENCES,
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->workflows->find($id) === null) {
            return $response->withStatus(404);
        }

        $data = $this->extract($request);
        $errors = $this->validate($data);
        if ($errors !== []) {
            $data['id'] = $id;
            return $this->formError($response, $data, $errors, 'edit', '/admin/email-folyamatok/' . $id);
        }

        $this->workflows->update($id, $data);
        $this->audit->log('email_workflow.update', 'email_workflow', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Folyamat frissítve.'];

        return $this->redirect($response, '/admin/email-folyamatok');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->workflows->find($id) !== null) {
            $this->workflows->delete($id);
            $this->audit->log('email_workflow.delete', 'email_workflow', $id);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Folyamat törölve.'];
        }

        return $this->redirect($response, '/admin/email-folyamatok');
    }

    /**
     * Kézi futtatás: a folyamat sablonját elküldi minden, e-mail címmel rendelkező
     * ügyfélnek, és minden kísérletet naplóz az email_sends táblába.
     */
    public function run(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $workflow = $this->workflows->find($id);
        if ($workflow === null) {
            return $response->withStatus(404);
        }

        $officeId = (int) $this->auth->officeId();

        $template = $workflow['template_id'] !== null
            ? $this->templates->find((int) $workflow['template_id'])
            : null;
        if ($template === null) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'A folyamathoz nincs érvényes sablon rendelve.'];
            return $this->redirect($response, '/admin/email-folyamatok');
        }

        if (!$this->mail->isConfigured($officeId)) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Az iroda SMTP-beállításai hiányoznak, ezért a küldés nem indult el. Töltsd ki a Beállításokban.'];
            return $this->redirect($response, '/admin/email-folyamatok');
        }

        $subject = (string) $template['subject'];
        $bodyHtml = (string) $template['body'];

        $sent = 0;
        $failed = 0;
        foreach ($this->clientEmails($officeId) as $email) {
            $status = 'sent';
            $error = null;
            try {
                $this->mail->send($officeId, $email, $subject, $bodyHtml);
                $sent++;
            } catch (Throwable $e) {
                $status = 'failed';
                $error = $e->getMessage();
                $failed++;
            }

            $this->sends->create([
                'workflow_id' => $id,
                'to_email' => $email,
                'subject' => $subject,
                'status' => $status,
                'error' => $error,
                'sent_at' => $status === 'sent' ? date('Y-m-d H:i:s') : null,
            ]);
        }

        $this->audit->log('email_workflow.run', 'email_workflow', $id);
        $_SESSION['flash'] = [
            'type' => $failed === 0 ? 'success' : 'error',
            'msg' => sprintf('%d elküldve, %d sikertelen.', $sent, $failed),
        ];

        return $this->redirect($response, '/admin/email-folyamatok');
    }

    /**
     * Az iroda ügyfeleinek nem üres e-mail címei.
     *
     * @return list<string>
     */
    private function clientEmails(int $officeId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT email FROM clients WHERE office_id = :o AND email IS NOT NULL AND email <> ''"
        );
        $stmt->execute(['o' => $officeId]);

        $emails = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
            $email = trim((string) $email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    /** @return array<int,array<string,mixed>> */
    private function templatesById(): array
    {
        $byId = [];
        foreach ($this->templates->listAll() as $template) {
            $byId[(int) $template['id']] = $template;
        }

        return $byId;
    }

    /** @return array<string,mixed> */
    private function extract(Request $request): array
    {
        $body = (array) $request->getParsedBody();

        $triggerType = (string) ($body['trigger_type'] ?? 'manual');
        if (!array_key_exists($triggerType, self::TRIGGER_TYPES)) {
            $triggerType = 'manual';
        }

        $audience = (string) ($body['audience'] ?? 'all_clients');
        if (!array_key_exists($audience, self::AUDIENCES)) {
            $audience = 'all_clients';
        }

        $templateId = trim((string) ($body['template_id'] ?? ''));
        $triggerDays = trim((string) ($body['trigger_days'] ?? ''));
        $scheduleAt = trim((string) ($body['schedule_at'] ?? ''));

        return [
            'name' => trim((string) ($body['name'] ?? '')),
            'template_id' => $templateId === '' ? null : (int) $templateId,
            'trigger_type' => $triggerType,
            'trigger_days' => $triggerDays === '' ? null : (int) $triggerDays,
            'audience' => $audience,
            'schedule_at' => $scheduleAt === '' ? null : str_replace('T', ' ', $scheduleAt) . ':00',
            'is_active' => isset($body['is_active']) ? 1 : 0,
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
            $errors['name'] = 'A folyamat nevének megadása kötelező (legalább 2 karakter).';
        }
        if ($data['template_id'] === null || $this->templates->find((int) $data['template_id']) === null) {
            $errors['template_id'] = 'Válassz egy érvényes sablont.';
        }
        if (in_array($data['trigger_type'], ['anniversary', 'expiry'], true)
            && ($data['trigger_days'] === null || (int) $data['trigger_days'] < 0)) {
            $errors['trigger_days'] = 'Add meg a napok számát (0 vagy nagyobb).';
        }

        return $errors;
    }

    /** @return array<string,mixed> */
    private function blank(): array
    {
        return [
            'name' => '',
            'template_id' => null,
            'trigger_type' => 'manual',
            'trigger_days' => null,
            'audience' => 'all_clients',
            'schedule_at' => null,
            'is_active' => 1,
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $errors
     */
    private function formError(Response $response, array $data, array $errors, string $mode, string $action): Response
    {
        return $this->twig->render($response->withStatus(422), 'admin/email/workflows_form.twig', [
            'active' => 'emails',
            'workflow' => $data,
            'errors' => $errors,
            'mode' => $mode,
            'action' => $action,
            'templates' => $this->templates->listAll(),
            'triggerTypes' => self::TRIGGER_TYPES,
            'audiences' => self::AUDIENCES,
        ]);
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
