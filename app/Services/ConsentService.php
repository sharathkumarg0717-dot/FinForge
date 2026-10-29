<?php
declare(strict_types=1);

namespace FinSync\Services;

use PDO;

final class ConsentService
{
    public function __construct(private PDO $db) {}

    public function granted(int $userId): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1 FROM consents WHERE user_id = :user_id AND family_member_id IS NULL '
            . 'AND scope = "financial_data" AND is_granted = 1 LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        return (bool)$statement->fetchColumn();
    }

    public function set(int $userId, bool $granted): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO consents (user_id, family_member_id, scope, is_granted, granted_at, revoked_at) '
            . 'VALUES (:user_id, NULL, "financial_data", :granted, :granted_at, :revoked_at) '
            . 'ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted), granted_at = VALUES(granted_at), revoked_at = VALUES(revoked_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'granted' => $granted ? 1 : 0,
            'granted_at' => $granted ? date('Y-m-d H:i:s') : null,
            'revoked_at' => $granted ? null : date('Y-m-d H:i:s'),
        ]);
    }
}
