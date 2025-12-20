<?php
namespace App\Models;

use App\Database\Database;

class Group {
    private Database $db;
    public int $group_id;
    public string $title;
    public ?string $username;
    public int $added_at = 0;
    public bool $active = true;

    public function __construct(Database $db) {
        $this->db = $db;
    }

    /**
     * Find group by ID
     */
    public static function find(Database $db, int $groupId): ?self {
        $stmt = $db->prepare("SELECT * FROM groups WHERE group_id = ?");
        $stmt->execute([$groupId]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$data) {
            return null;
        }

        return self::fromArray($db, $data);
    }

    /**
     * Find or create group
     */
    public static function findOrCreate(Database $db, array $chatData): self {
        $groupId = $chatData['id'];
        $group = self::find($db, $groupId);

        if (!$group) {
            $group = new self($db);
            $group->group_id = $groupId;
            $group->title = $chatData['title'] ?? 'Unknown';
            $group->username = $chatData['username'] ?? null;
            $group->added_at = time();
            $group->active = true;
            $group->save();
        } else {
            // Update title if changed
            if (($chatData['title'] ?? null) !== $group->title) {
                $group->title = $chatData['title'] ?? $group->title;
                $group->save();
            }
        }

        return $group;
    }

    /**
     * Create from array
     */
    public static function fromArray(Database $db, array $data): self {
        $group = new self($db);
        $group->group_id = (int)$data['group_id'];
        $group->title = $data['title'] ?? 'Unknown';
        $group->username = $data['username'] ?? null;
        $group->added_at = (int)($data['added_at'] ?? 0);
        $group->active = (bool)($data['active'] ?? true);
        return $group;
    }

    /**
     * Save group to database
     */
    public function save(): void {
        $this->db->replace('groups', [
            'group_id' => $this->group_id,
            'title' => $this->title,
            'username' => $this->username,
            'added_at' => $this->added_at ?: time(),
            'active' => $this->active ? 1 : 0
        ]);
    }

    /**
     * Deactivate group
     */
    public function deactivate(): void {
        $this->active = false;
        $this->save();
    }
}
