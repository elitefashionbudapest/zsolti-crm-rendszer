<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\Auth;
use App\Insurers\InsurerRepository;
use App\Insurers\InsurerRouteRepository;
use App\Support\AuditLogger;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Biztosítók és e-mail címlisták kezelése: lista, megtekintés, létrehozás,
 * szerkesztés, törlés, valamint kategória szerinti címlisták — tenant-tudatosan.
 */
final class InsurersController
{
    /** A szerződésekkel azonos kategória-kódok. */
    private const CATEGORIES = ['elet_egeszseg', 'vagyon', 'nyugdij_megtakaritas', 'befektetes'];

    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private InsurerRepository $insurers,
        private InsurerRouteRepository $routes,
        private AuditLogger $audit,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $rows = $this->insurers->listAll();
        $list = [];
        foreach ($rows as $insurer) {
            $insurer['email_count'] = count(
                InsurerRouteRepository::splitEmails((string) ($insurer['default_emails'] ?? ''))
            );
            $list[] = $insurer;
        }

        return $this->twig->render($response, 'admin/insurers/index.twig', [
            'active' => 'settings',
            'insurers' => $list,
            'flash' => $this->flash(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/insurers/form.twig', [
            'active' => 'settings',
            'insurer' => $this->blank(),
            'errors' => [],
            'mode' => 'create',
            'action' => '/admin/biztositok',
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $this->extract($request);
        $errors = $this->validate($data);
        if ($errors !== []) {
            return $this->twig->render($response->withStatus(422), 'admin/insurers/form.twig', [
                'active' => 'settings', 'insurer' => $data, 'errors' => $errors, 'mode' => 'create', 'action' => '/admin/biztositok',
            ]);
        }

        $id = $this->insurers->create($data);
        $this->audit->log('insurer.create', 'insurer', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Biztosító létrehozva.'];

        return $this->redirect($response, '/admin/biztositok/' . $id);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $insurer = $this->insurers->find((int) $args['id']);
        if ($insurer === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'admin/insurers/show.twig', [
            'active' => 'settings',
            'insurer' => $insurer,
            'routes' => $this->routes->forInsurer((int) $insurer['id']),
            'categories' => self::CATEGORIES,
            'categoryLabels' => $this->categoryLabels(),
            'flash' => $this->flash(),
        ]);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $insurer = $this->insurers->find((int) $args['id']);
        if ($insurer === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'admin/insurers/form.twig', [
            'active' => 'settings',
            'insurer' => $insurer,
            'errors' => [],
            'mode' => 'edit',
            'action' => '/admin/biztositok/' . $insurer['id'],
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->insurers->find($id) === null) {
            return $response->withStatus(404);
        }

        $data = $this->extract($request);
        $errors = $this->validate($data);
        if ($errors !== []) {
            $data['id'] = $id;
            return $this->twig->render($response->withStatus(422), 'admin/insurers/form.twig', [
                'active' => 'settings', 'insurer' => $data, 'errors' => $errors, 'mode' => 'edit', 'action' => '/admin/biztositok/' . $id,
            ]);
        }

        $this->insurers->update($id, $data);
        $this->audit->log('insurer.update', 'insurer', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Biztosító frissítve.'];

        return $this->redirect($response, '/admin/biztositok/' . $id);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->insurers->find($id) !== null) {
            $this->insurers->delete($id);
            $this->audit->log('insurer.delete', 'insurer', $id);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Biztosító törölve.'];
        }

        return $this->redirect($response, '/admin/biztositok');
    }

    public function addRoute(Request $request, Response $response, array $args): Response
    {
        $insurerId = (int) $args['id'];
        if ($this->insurers->find($insurerId) === null) {
            return $response->withStatus(404);
        }

        $body = (array) $request->getParsedBody();
        $category = trim((string) ($body['category'] ?? ''));
        $emails = trim((string) ($body['emails'] ?? ''));

        $valid = InsurerRouteRepository::splitEmails($emails);
        if ($valid === []) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Adj meg legalább egy érvényes e-mail címet.'];

            return $this->redirect($response, '/admin/biztositok/' . $insurerId);
        }

        if ($category !== '' && !in_array($category, self::CATEGORIES, true)) {
            $category = '';
        }

        $routeId = $this->routes->create([
            'insurer_id' => $insurerId,
            'category' => $category === '' ? null : $category,
            'emails' => $emails,
        ]);
        $this->audit->log('insurer.route.create', 'insurer_email_route', $routeId);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Címlista hozzáadva.'];

        return $this->redirect($response, '/admin/biztositok/' . $insurerId);
    }

    public function deleteRoute(Request $request, Response $response, array $args): Response
    {
        $insurerId = (int) $args['id'];
        $routeId = (int) $args['routeId'];

        $route = $this->routes->find($routeId);
        if ($route !== null && (int) $route['insurer_id'] === $insurerId) {
            $this->routes->delete($routeId);
            $this->audit->log('insurer.route.delete', 'insurer_email_route', $routeId);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Címlista törölve.'];
        }

        return $this->redirect($response, '/admin/biztositok/' . $insurerId);
    }

    /** @return array<string,mixed> */
    private function extract(Request $request): array
    {
        $body = (array) $request->getParsedBody();
        $name = trim((string) ($body['name'] ?? ''));
        $defaultEmails = trim((string) ($body['default_emails'] ?? ''));

        return [
            'name' => $name === '' ? null : $name,
            'default_emails' => $defaultEmails === '' ? null : $defaultEmails,
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
        if ($data['name'] === null || mb_strlen((string) $data['name']) < 2) {
            $errors['name'] = 'A név megadása kötelező (legalább 2 karakter).';
        }

        return $errors;
    }

    /** @return array<string,mixed> */
    private function blank(): array
    {
        return [
            'name' => null,
            'default_emails' => null,
            'is_active' => 1,
        ];
    }

    /** @return array<string,string> */
    private function categoryLabels(): array
    {
        return [
            'elet_egeszseg' => 'Élet- és egészségbiztosítás',
            'vagyon' => 'Vagyonbiztosítás',
            'nyugdij_megtakaritas' => 'Nyugdíj-megtakarítás',
            'befektetes' => 'Befektetés',
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
