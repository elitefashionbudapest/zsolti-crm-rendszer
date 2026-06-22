<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\Auth;
use App\Clients\ClientRepository;
use App\Mail\MailService;
use App\Support\AuditLogger;
use Slim\Views\Twig;
use Throwable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Partnerek (ügyfelek) kezelése: lista (keresés/szűrés), megtekintés, létrehozás,
 * szerkesztés, törlés — tenant-tudatosan.
 */
final class ClientsController
{
    private const FIELDS = ['name', 'email', 'phone', 'mobile', 'address', 'tax_id', 'birth_date', 'birth_place', 'mother_name', 'notes', 'status'];

    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private ClientRepository $clients,
        private AuditLogger $audit,
        private MailService $mail,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $q = (array) $request->getQueryParams();
        $search = trim((string) ($q['q'] ?? ''));
        $status = (string) ($q['status'] ?? '');
        $page = max(1, (int) ($q['page'] ?? 1));

        $result = $this->clients->paginate($search, $status, $page);

        return $this->twig->render($response, 'admin/clients/index.twig', [
            'active' => 'clients',
            'list' => $result,
            'search' => $search,
            'status' => $status,
            'flash' => $this->flash(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/clients/form.twig', [
            'active' => 'clients',
            'client' => $this->blank(),
            'errors' => [],
            'mode' => 'create',
            'action' => '/admin/partnerek',
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $this->extract($request);
        $errors = $this->validate($data);
        if ($errors !== []) {
            return $this->twig->render($response->withStatus(422), 'admin/clients/form.twig', [
                'active' => 'clients', 'client' => $data, 'errors' => $errors, 'mode' => 'create', 'action' => '/admin/partnerek',
            ]);
        }

        $data['owner_user_id'] = $this->auth->id();
        $id = $this->clients->create($data);
        $this->audit->log('client.create', 'client', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Partner létrehozva.'];

        return $this->redirect($response, '/admin/partnerek/' . $id);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $client = $this->clients->find((int) $args['id']);
        if ($client === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'admin/clients/show.twig', [
            'active' => 'clients',
            'client' => $client,
            'contractCount' => $this->clients->contractCount((int) $client['id']),
            'contracts' => $this->clients->contractsFor((int) $client['id']),
            'documents' => $this->clients->documentsFor((int) $client['id']),
            'tasks' => $this->clients->tasksFor((int) $client['id']),
            'intake' => $this->clients->intakeFor((int) $client['id']),
            'notes' => $this->clients->notesFor((int) $client['id']),
            'mailConfigured' => $this->mail->isConfigured((int) ($this->auth->officeId() ?? 0)),
            'flash' => $this->flash(),
        ]);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $client = $this->clients->find((int) $args['id']);
        if ($client === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'admin/clients/form.twig', [
            'active' => 'clients',
            'client' => $client,
            'errors' => [],
            'mode' => 'edit',
            'action' => '/admin/partnerek/' . $client['id'],
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->clients->find($id) === null) {
            return $response->withStatus(404);
        }

        $data = $this->extract($request);
        $errors = $this->validate($data);
        if ($errors !== []) {
            $data['id'] = $id;
            return $this->twig->render($response->withStatus(422), 'admin/clients/form.twig', [
                'active' => 'clients', 'client' => $data, 'errors' => $errors, 'mode' => 'edit', 'action' => '/admin/partnerek/' . $id,
            ]);
        }

        $this->clients->update($id, $data);
        $this->audit->log('client.update', 'client', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Partner frissítve.'];

        return $this->redirect($response, '/admin/partnerek/' . $id);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->clients->find($id) !== null) {
            $this->clients->delete($id);
            $this->audit->log('client.delete', 'client', $id);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Partner törölve.'];
        }

        return $this->redirect($response, '/admin/partnerek');
    }

    /** Üzenet (e-mail) küldése a partnernek az iroda SMTP-jén. */
    public function sendMessage(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $client = $this->clients->find($id);
        if ($client === null) {
            return $response->withStatus(404);
        }

        $body = (array) $request->getParsedBody();
        $subject = trim((string) ($body['subject'] ?? ''));
        $message = trim((string) ($body['message'] ?? ''));
        $to = (string) ($client['email'] ?? '');

        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'A partnernek nincs érvényes e-mail címe.'];
            return $this->redirect($response, '/admin/partnerek/' . $id);
        }
        if ($subject === '' || $message === '') {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'A tárgy és az üzenet megadása kötelező.'];
            return $this->redirect($response, '/admin/partnerek/' . $id);
        }

        $html = '<p>' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>';
        $officeId = (int) ($this->auth->officeId() ?? 0);

        try {
            $this->mail->send($officeId, $to, $subject, $html);
            $this->clients->logEmail($to, $subject, 'sent', null);
            $this->audit->log('client.message', 'client', $id);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Üzenet elküldve a partnernek.'];
        } catch (Throwable $e) {
            $this->clients->logEmail($to, $subject, 'failed', $e->getMessage());
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Az üzenet nem ment el: ' . $e->getMessage()];
        }

        return $this->redirect($response, '/admin/partnerek/' . $id);
    }

    /** Belső megjegyzés hozzáadása a partnerhez (csak az iroda látja). */
    public function addNote(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->clients->find($id) === null) {
            return $response->withStatus(404);
        }

        $note = trim((string) (((array) $request->getParsedBody())['body'] ?? ''));
        if ($note === '') {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Üres megjegyzést nem lehet menteni.'];
            return $this->redirect($response, '/admin/partnerek/' . $id);
        }

        $this->clients->addNote($id, $this->auth->id(), $note);
        $this->audit->log('client.note', 'client', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Megjegyzés hozzáadva.'];

        return $this->redirect($response, '/admin/partnerek/' . $id);
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
        if ($data['name'] === null || mb_strlen((string) $data['name']) < 2) {
            $errors['name'] = 'A név megadása kötelező (legalább 2 karakter).';
        }
        if ($data['email'] !== null && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Érvénytelen e-mail cím.';
        }
        if ($data['birth_date'] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data['birth_date'])) {
            $errors['birth_date'] = 'A dátum formátuma ÉÉÉÉ-HH-NN.';
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
