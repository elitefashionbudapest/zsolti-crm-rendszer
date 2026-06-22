<?php

declare(strict_types=1);

namespace App\Auth;

use PDO;

/**
 * Felhasználók lekérdezése a belépéshez. A belépés e-mail alapján, iroda-
 * függetlenül történik (a felhasználó hozza magával az office_id-t).
 */
final class UserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM users WHERE email = :email AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['email' => mb_strtolower(trim($email))]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<string> a felhasználó szerepkódjai */
    public function rolesFor(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.code FROM roles r
             INNER JOIN role_user ru ON ru.role_id = r.id
             WHERE ru.user_id = :uid'
        );
        $stmt->execute(['uid' => $userId]);

        return array_map(static fn (array $r): string => (string) $r['code'], $stmt->fetchAll());
    }

    public function touchLogin(int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET last_login_at = :now WHERE id = :id');
        $stmt->execute(['now' => date('Y-m-d H:i:s'), 'id' => $userId]);
    }
}
