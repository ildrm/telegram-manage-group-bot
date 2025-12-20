<?php
namespace App\Models;

use App\Database\Database;

class User {
    private Database $db;
    public int $user_id;
    public ?string $username;
    public ?string $first_name;
    public ?string $last_name;
    public int $reputation = 0;
    public int $trust_level = 0;
    public int $created_at = 0;

    public function __construct(Database $db) {
        $this->db = $db;
    }

    /**
     * Find user by ID
     */
    public static function find(Database $db, int $userId): ?self {
        $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$data) {
            return null;
        }

        return self::fromArray($db, $data);
    }

    /**
     * Find or create user
     */
    public static function findOrCreate(Database $db, array $userData): self {
        $userId = $userData['id'];
        $user = self::find($db, $userId);

        if (!$user) {
            $user = new self($db);
            $user->user_id = $userId;
            $user->username = $userData['username'] ?? null;
            $user->first_name = $userData['first_name'] ?? null;
            $user->last_name = $userData['last_name'] ?? null;
            $user->created_at = time();
            $user->save();
        } else {
            // Update user info if changed
            $updated = false;
            if (($userData['username'] ?? null) !== $user->username) {
                $user->username = $userData['username'] ?? null;
                $updated = true;
            }
            if (($userData['first_name'] ?? null) !== $user->first_name) {
                $user->first_name = $userData['first_name'] ?? null;
                $updated = true;
            }
            if (($userData['last_name'] ?? null) !== $user->last_name) {
                $user->last_name = $userData['last_name'] ?? null;
                $updated = true;
            }
            if ($updated) {
                $user->save();
            }
        }

        return $user;
    }

    /**
     * Create from array
     */
    public static function fromArray(Database $db, array $data): self {
        $user = new self($db);
        $user->user_id = (int)$data['user_id'];
        $user->username = $data['username'] ?? null;
        $user->first_name = $data['first_name'] ?? null;
        $user->last_name = $data['last_name'] ?? null;
        $user->reputation = (int)($data['reputation'] ?? 0);
        $user->trust_level = (int)($data['trust_level'] ?? 0);
        $user->created_at = (int)($data['created_at'] ?? 0);
        return $user;
    }

    /**
     * Save user to database
     */
    public function save(): void {
        $this->db->replace('users', [
            'user_id' => $this->user_id,
            'username' => $this->username,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'reputation' => $this->reputation,
            'trust_level' => $this->trust_level,
            'created_at' => $this->created_at ?: time()
        ]);
    }

    /**
     * Get display name
     */
    public function getDisplayName(): string {
        if ($this->first_name) {
            return $this->first_name . ($this->last_name ? ' ' . $this->last_name : '');
        }
        return $this->username ?? "User {$this->user_id}";
    }
}
