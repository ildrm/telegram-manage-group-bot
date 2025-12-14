<?php
namespace App\Modules;

use App\Interfaces\PluginInterface;
use App\Core\Container;
use App\Telegram\Client;
use App\Database\Database;

class ModerationModule implements PluginInterface {
    public function register(Container $container): void {
        // Validation services could be registered here
    }

    public function boot(Container $container): void {
    }

    public function getListeners(): array {
        return [
            'update.received' => 'handleMessage'
        ];
    }

    public function handleMessage(array $update, Container $container): void {
        if (!isset($update['message'])) return;

        $message = $update['message'];
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
        $text = $message['text'] ?? '';
        
        // Skip private chats
        if ($message['chat']['type'] === 'private') return;

        // Skip Admins (TODO: Check real admin status)
        // For now, check whitelist or owner table
        if ($this->isAdmin($userId, $chatId, $container)) return;

        $client = $container->get(Client::class);
        $db = $container->get(Database::class);
        
        // 1. Flood Control
        if ($this->checkFlood($userId, $chatId, $db)) {
            $client->sendMessage($chatId, "⚠️ @{$message['from']['username']}, you are sending messages too fast.");
            // $client->deleteMessage($chatId, $message['message_id']); // Need to implement delete
            return;
        }

        // 2. Anti-Link
        if (preg_match('/(https?:\/\/|t\.me\/|@\w+)/i', $text)) {
            // Check settings (Mocking settings for now)
            // In real impl, fetch from DB
            $client->sendMessage($chatId, "🚫 Links are not allowed.");
            return;
        }
        
        // 3. Risk Scoring (Basic Entropy)
        $score = $this->calculateRiskScore($text);
        if ($score > 80) {
            $client->sendMessage($chatId, "⚠️ Message detected as potential spam (Risk: $score%).");
        }
    }

    private function isAdmin(int $userId, int $groupId, Container $container): bool {
        // Implement logic to check if user is admin/owner
        $db = $container->get(Database::class);
        $stmt = $db->prepare("SELECT 1 FROM group_owners WHERE group_id = ? AND user_id = ?");
        $stmt->bindValue(1, $groupId);
        $stmt->bindValue(2, $userId);
        $result = $stmt->execute()->fetchArray();
        return (bool)$result;
    }

    private function checkFlood(int $userId, int $groupId, Database $db): bool {
        // Simple rate limit implementation
        // Use a static cache or DB for now
        // using DB for persistence as requested
        
        $now = time();
        $window = 10; // 10 seconds
        $limit = 5; // 5 messages
        
        // Clean old
        $db->exec("DELETE FROM rate_limits WHERE timestamp < " . ($now - $window));
        
        // Count
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM rate_limits WHERE user_id = ? AND timestamp > ?");
        $stmt->bindValue(1, $userId);
        $stmt->bindValue(2, $now - $window);
        $result = $stmt->execute()->fetchArray();
        
        if ($result['cnt'] >= $limit) {
            return true;
        }
        
        // Log
        $stmt = $db->prepare("INSERT INTO rate_limits (user_id, timestamp) VALUES (?, ?)");
        $stmt->bindValue(1, $userId);
        $stmt->bindValue(2, $now);
        $stmt->execute();
        
        return false;
    }

    private function calculateRiskScore(string $text): int {
        $score = 0;
        $len = strlen($text);
        if ($len == 0) return 0;

        // CAPS LOCK
        $upper = preg_match_all('/[A-Z]/', $text);
        if ($len > 10 && ($upper / $len) > 0.7) $score += 30;

        // Emoji density
        // Simple regex for some emoji ranges (incomplete but works for demo)
        // $emojiCount = preg_match_all('/[\x{1F600}-\x{1F64F}]/u', $text); 
        
        // Repetitive characters
        if (preg_match('/(.)\1{4,}/', $text)) $score += 20;

        return min($score, 100);
    }
}
