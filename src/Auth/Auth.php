<?php

declare(strict_types=1);

namespace App\Auth;

use App\Tenant\TenantContext;

/**
 * Munkamenet-alapú hitelesítés. Jelszó: argon2id (password_hash/verify).
 * Sikeres belépéskor a felhasználó adatait és szerepköreit a session tárolja,
 * és beállítja a tenant-kontextust (office_id).
 */
final class Auth
{
    public function __construct(
        private UserRepository $users,
        private TenantContext $tenant,
    ) {
    }

    /**
     * Belépés-kísérlet. Csak a megengedett szerepkörök egyikével léphet be.
     *
     * @param list<string> $allowedRoles
     */
    public function attempt(string $email, string $password, array $allowedRoles): bool
    {
        $user = $this->users->findByEmail($email);
        if ($user === null) {
            // Időzítés-támadás elleni dummy ellenőrzés.
            password_verify($password, '$argon2id$v=19$m=65536,t=4,p=1$' . str_repeat('a', 22) . '$' . str_repeat('b', 43));
            return false;
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        $roles = $this->users->rolesFor((int) $user['id']);
        if (array_intersect($roles, $allowedRoles) === []) {
            return false;
        }

        $this->establish($user, $roles);
        $this->users->touchLogin((int) $user['id']);

        return true;
    }

    /** @param array<string,mixed> $user @param list<string> $roles */
    private function establish(array $user, array $roles): void
    {
        // Session fixation ellen: új session id a belépéskor.
        session_regenerate_id(true);

        $_SESSION['auth'] = [
            'user_id' => (int) $user['id'],
            'office_id' => $user['office_id'] !== null ? (int) $user['office_id'] : null,
            'client_id' => isset($user['client_id']) && $user['client_id'] !== null ? (int) $user['client_id'] : null,
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'roles' => $roles,
        ];

        $this->bindTenant();
    }

    /** A session-ből beállítja a tenant-kontextust (minden kérés elején hívjuk). */
    public function bindTenant(): void
    {
        $auth = $_SESSION['auth'] ?? null;
        if (!is_array($auth)) {
            return;
        }
        $roles = $auth['roles'] ?? [];
        $this->tenant->set(
            $auth['office_id'] !== null ? (int) $auth['office_id'] : null,
            in_array('super_admin', $roles, true),
        );
    }

    public function check(): bool
    {
        return isset($_SESSION['auth']['user_id']);
    }

    /** @return array<string,mixed>|null */
    public function user(): ?array
    {
        return $_SESSION['auth'] ?? null;
    }

    public function id(): ?int
    {
        return isset($_SESSION['auth']['user_id']) ? (int) $_SESSION['auth']['user_id'] : null;
    }

    public function clientId(): ?int
    {
        return isset($_SESSION['auth']['client_id']) ? (int) $_SESSION['auth']['client_id'] : null;
    }

    public function officeId(): ?int
    {
        return isset($_SESSION['auth']['office_id']) ? (int) $_SESSION['auth']['office_id'] : null;
    }

    /** @return list<string> */
    public function roles(): array
    {
        return $_SESSION['auth']['roles'] ?? [];
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles(), true);
    }

    /** @param list<string> $roles */
    public function hasAnyRole(array $roles): bool
    {
        return array_intersect($roles, $this->roles()) !== [];
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        $this->tenant->set(null);
    }
}
