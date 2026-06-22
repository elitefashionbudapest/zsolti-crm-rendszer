<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\Auth;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Belépés: külön ügynöki/admin és ügyfél bejelentkezés, egyszerű
 * sebességkorlátozással (brute-force ellen). A CSRF-védelmet a globális Guard adja.
 */
final class LoginController
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW = 900; // 15 perc

    public function __construct(
        private Twig $twig,
        private Auth $auth,
    ) {
    }

    public function showAgent(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'auth/login.twig', [
            'audience' => 'agent',
            'title' => 'Ügynöki / Admin belépés',
            'subtitle' => 'Tanácsadóknak és adminisztrátoroknak',
            'action' => '/belepes/ugynok',
            'accent' => 'ink',
            'error' => $this->flashError(),
        ]);
    }

    public function showClient(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'auth/login.twig', [
            'audience' => 'client',
            'title' => 'Ügyfél belépés',
            'subtitle' => 'Szerződések és dokumentumok megtekintése',
            'action' => '/belepes/ugyfel',
            'accent' => 'teal',
            'error' => $this->flashError(),
        ]);
    }

    public function agentLogin(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, ['owner', 'assistant', 'super_admin'], '/belepes/ugynok');
    }

    public function clientLogin(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, ['client'], '/belepes/ugyfel');
    }

    /** @param list<string> $roles */
    private function handle(Request $request, Response $response, array $roles, string $back): Response
    {
        if ($this->throttled()) {
            $_SESSION['flash_error'] = 'Túl sok sikertelen kísérlet. Próbáld újra később.';
            return $this->redirect($response, $back);
        }

        $data = (array) $request->getParsedBody();
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($email === '' || $password === '') {
            $_SESSION['flash_error'] = 'Add meg az e-mail címet és a jelszót.';
            return $this->redirect($response, $back);
        }

        if (!$this->auth->attempt($email, $password, $roles)) {
            $this->recordFailure();
            $_SESSION['flash_error'] = 'Hibás e-mail cím vagy jelszó.';
            return $this->redirect($response, $back);
        }

        $this->clearFailures();

        $target = $this->auth->hasRole('super_admin')
            ? '/superadmin'
            : ($this->auth->hasRole('client') ? '/portal' : '/admin');

        return $this->redirect($response, $target);
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->auth->logout();
        return $this->redirect($response, '/');
    }

    private function throttled(): bool
    {
        $a = $_SESSION['login_attempts'] ?? null;
        if (!is_array($a)) {
            return false;
        }
        if ((time() - (int) $a['first']) > self::WINDOW) {
            unset($_SESSION['login_attempts']);
            return false;
        }
        return (int) $a['count'] >= self::MAX_ATTEMPTS;
    }

    private function recordFailure(): void
    {
        $a = $_SESSION['login_attempts'] ?? ['count' => 0, 'first' => time()];
        if ((time() - (int) $a['first']) > self::WINDOW) {
            $a = ['count' => 0, 'first' => time()];
        }
        $a['count'] = (int) $a['count'] + 1;
        $_SESSION['login_attempts'] = $a;
    }

    private function clearFailures(): void
    {
        unset($_SESSION['login_attempts']);
    }

    private function flashError(): ?string
    {
        $e = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);
        return $e === null ? null : (string) $e;
    }

    private function redirect(Response $response, string $to): Response
    {
        return $response->withHeader('Location', $to)->withStatus(302);
    }
}
