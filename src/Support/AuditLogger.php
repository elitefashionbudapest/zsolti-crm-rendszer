<?php

declare(strict_types=1);

namespace App\Support;

use App\Auth\Auth;
use PDO;
use Throwable;

/**
 * Egyszerű audit-napló az érzékeny műveletekhez (titkok nélkül).
 */
final class AuditLogger
{
    public function __construct(
        private PDO $pdo,
        private Auth $auth,
    ) {
    }

    public function log(string $action, ?string $entity = null, ?int $entityId = null): void
    {
        try {
            $user = $this->auth->user();
            $stmt = $this->pdo->prepare(
                'INSERT INTO audit_logs (office_id, user_id, action, entity, entity_id, ip, created_at)
                 VALUES (:o, :u, :a, :e, :eid, :ip, :ts)'
            );
            $stmt->execute([
                'o' => $user['office_id'] ?? null,
                'u' => $user['user_id'] ?? null,
                'a' => $action,
                'e' => $entity,
                'eid' => $entityId,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'ts' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {
            // A naplózás hibája soha ne állítsa meg a fő műveletet.
        }
    }
}
