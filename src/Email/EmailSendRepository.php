<?php

declare(strict_types=1);

namespace App\Email;

use App\Database\Repository;

/**
 * E-mail kiküldési napló adat-rétege, tenant-tudatosan (office_id).
 */
final class EmailSendRepository extends Repository
{
    protected function table(): string
    {
        return 'email_sends';
    }

    /**
     * Lapozható napló-lista.
     *
     * @return array{rows: array<int,array<string,mixed>>, total: int, page: int, pages: int, perPage: int}
     */
    public function paginate(int $page = 1, int $perPage = 30): array
    {
        $office = $this->tenant->requireOfficeId();

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM email_sends WHERE office_id = :o');
        $countStmt->execute(['o' => $office]);
        $total = (int) ($countStmt->fetch()['c'] ?? 0);

        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            "SELECT * FROM email_sends WHERE office_id = :o ORDER BY id DESC LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute(['o' => $office]);

        return [
            'rows' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'perPage' => $perPage,
        ];
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $data['created_at'] = $data['updated_at'] = date('Y-m-d H:i:s');

        return $this->tenantInsert($data);
    }
}
