<?php
namespace App\Services;

use App\Database\Database;
use App\Telegram\Client;

class AuthorizationService {
    private Database $db;
    private Client $client;

    public function __construct(Database $db, Client $client) {
        $this->db = $db;
        $this->client = $client;
    }

    /**
     * Check if user owns/manages a group
     */
    public function isOwner(int $userId, int $groupId): bool {
        $stmt = $this->db->prepare("SELECT 1 FROM group_owners WHERE group_id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$groupId, $userId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Check if user is admin in Telegram (not just owner in our DB)
     */
    public function isTelegramAdmin(int $userId, int $groupId): bool {
        try {
            $result = $this->client->getChatMember($groupId, $userId);
            if ($result && isset($result['result'])) {
                $status = $result['result']['status'] ?? '';
                return in_array($status, ['administrator', 'creator']);
            }
        } catch (\Exception $e) {
            // If we can't check, fall back to owner check
        }
        return false;
    }

    /**
     * Check if user can manage a group (owner or admin)
     */
    public function canManage(int $userId, int $groupId): bool {
        return $this->isOwner($userId, $groupId) || $this->isTelegramAdmin($userId, $groupId);
    }

    /**
     * Add owner to group
     */
    public function addOwner(int $groupId, int $userId, string $role = 'owner'): void {
        $this->db->insertIgnore('group_owners', [
            'group_id' => $groupId,
            'user_id' => $userId,
            'role' => $role,
            'added_at' => time()
        ]);
    }

    /**
     * Remove owner from group
     */
    public function removeOwner(int $groupId, int $userId): void {
        $stmt = $this->db->prepare("DELETE FROM group_owners WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$groupId, $userId]);
    }

    /**
     * Get all owners of a group
     */
    public function getOwners(int $groupId): array {
        $stmt = $this->db->prepare("SELECT user_id, role, added_at FROM group_owners WHERE group_id = ?");
        $stmt->execute([$groupId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
