<?php
namespace App\Modules;

use App\Interfaces\PluginInterface;
use App\Core\Container;
use App\Database\Database;
use App\Telegram\Client;

class AdminModule implements PluginInterface {
    public function register(Container $container): void {
    }

    public function boot(Container $container): void {
    }

    public function getListeners(): array {
        return [
            'update.received' => 'handleUpdate'
        ];
    }

    public function handleUpdate(array $update, Container $container): void {
        if (!isset($update['message']['text'])) return;

        $message = $update['message'];
        $text = $message['text'];
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
        
        $client = $container->get(Client::class);
        $db = $container->get(Database::class);

        // Command: /promote @user
        if (strpos($text, '/promote') === 0) {
            if (!$this->isOwner($userId, $chatId, $db)) {
                $client->sendMessage($chatId, "⛔ You must be the group owner to do this.");
                return;
            }
            
            // Extract username or reply
            $targetId = $this->getTargetUser($message);
            if (!$targetId) {
                $client->sendMessage($chatId, "Reply to a user or mention them to promote.");
                return;
            }
            
            $this->setRole($chatId, $targetId, 'moderator', $db);
            $client->sendMessage($chatId, "✅ User promoted to Moderator.");
        }
        
        // Command: /demote @user
        if (strpos($text, '/demote') === 0) {
             if (!$this->isOwner($userId, $chatId, $db)) {
                $client->sendMessage($chatId, "⛔ Access denied.");
                return;
            }
            
            $targetId = $this->getTargetUser($message);
            if ($targetId) {
                $this->removeRole($chatId, $targetId, $db);
                $client->sendMessage($chatId, "✅ User demoted.");
            }
        }
    }
    
    private function getTargetUser(array $message): ?int {
        if (isset($message['reply_to_message'])) {
            return $message['reply_to_message']['from']['id'];
        }
        // Handle mentions... (simplified for now)
        return null;
    }
    
    private function isOwner(int $userId, int $groupId, Database $db): bool {
        $stmt = $db->prepare("SELECT role FROM group_owners WHERE group_id = ? AND user_id = ?");
        $stmt->bindValue(1, $groupId);
        $stmt->bindValue(2, $userId);
        $result = $stmt->execute()->fetchArray();
        return $result && $result['role'] === 'owner';
    }
    
    private function setRole(int $groupId, int $userId, string $role, Database $db): void {
        $stmt = $db->prepare("INSERT OR REPLACE INTO group_owners (group_id, user_id, role) VALUES (?, ?, ?)");
        $stmt->bindValue(1, $groupId);
        $stmt->bindValue(2, $userId);
        $stmt->bindValue(3, $role);
        $stmt->execute();
    }
    
    private function removeRole(int $groupId, int $userId, Database $db): void {
        $stmt = $db->prepare("DELETE FROM group_owners WHERE group_id = ? AND user_id = ? AND role != 'owner'");
        $stmt->bindValue(1, $groupId);
        $stmt->bindValue(2, $userId);
        $stmt->execute();
    }
}
