<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Alap szerepek + demó iroda és felhasználók a fejlesztéshez/teszteléshez.
 * Demó jelszó mindenkinél: Titok1234!
 */
final class DemoSeeder extends AbstractSeed
{
    public function run(): void
    {
        /** @var PDO $pdo */
        $pdo = $this->getAdapter()->getConnection();
        $now = date('Y-m-d H:i:s');
        $pass = password_hash('Titok1234!', PASSWORD_DEFAULT);

        // Szerepek
        $roles = [
            'super_admin' => 'Szuperadmin',
            'owner' => 'Iroda tulajdonos',
            'assistant' => 'Asszisztens',
            'client' => 'Ügyfél',
        ];
        $roleId = [];
        foreach ($roles as $code => $name) {
            $pdo->prepare('INSERT INTO roles (code, name) VALUES (:c, :n)')->execute(['c' => $code, 'n' => $name]);
            $roleId[$code] = (int) $pdo->lastInsertId();
        }

        // Demó iroda
        $pdo->prepare('INSERT INTO offices (name, slug, is_active, created_at, updated_at) VALUES (:n, :s, 1, :c, :u)')
            ->execute(['n' => 'Demó Biztosítási Iroda', 's' => 'demo', 'c' => $now, 'u' => $now]);
        $officeId = (int) $pdo->lastInsertId();

        // Felhasználók
        $makeUser = function (?int $office, string $name, string $email) use ($pdo, $pass, $now): int {
            $pdo->prepare(
                'INSERT INTO users (office_id, name, email, password_hash, is_active, created_at, updated_at)
                 VALUES (:o, :n, :e, :p, 1, :c, :u)'
            )->execute([
                'o' => $office, 'n' => $name, 'e' => mb_strtolower($email), 'p' => $pass, 'c' => $now, 'u' => $now,
            ]);
            return (int) $pdo->lastInsertId();
        };
        $assign = function (int $user, string $role) use ($pdo, $roleId): void {
            $pdo->prepare('INSERT INTO role_user (user_id, role_id) VALUES (:u, :r)')
                ->execute(['u' => $user, 'r' => $roleId[$role]]);
        };

        $sa = $makeUser(null, 'Fő Adminisztrátor', 'superadmin@aegis.test');
        $assign($sa, 'super_admin');

        $agent = $makeUser($officeId, 'Kis Balázs', 'ugynok@aegis.test');
        $assign($agent, 'owner');

        // Demó ügyfél + portál-fiók
        $pdo->prepare(
            'INSERT INTO clients (office_id, owner_user_id, name, email, status, created_at, updated_at)
             VALUES (:o, :w, :n, :e, :s, :c, :u)'
        )->execute(['o' => $officeId, 'w' => $agent, 'n' => 'Kovács Anna', 'e' => 'ugyfel@aegis.test', 's' => 'active', 'c' => $now, 'u' => $now]);
        $clientId = (int) $pdo->lastInsertId();

        $client = $makeUser($officeId, 'Kovács Anna', 'ugyfel@aegis.test');
        $pdo->prepare('UPDATE users SET client_id = :cid WHERE id = :id')->execute(['cid' => $clientId, 'id' => $client]);
        $assign($client, 'client');
    }
}
