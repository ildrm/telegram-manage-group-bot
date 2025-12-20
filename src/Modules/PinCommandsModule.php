<?php
namespace App\Modules;

use App\Interfaces\PluginInterface;
use App\Core\Container;
use App\Database\Database;
use App\Telegram\Client;

class PinCommandsModule implements PluginInterface {
    public function register(Container $container): void {}
    
    public function boot(Container $container): void {}
    
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
        $chatType = $message['chat']['type'];
        
        // Only work in groups
        if ($chatType === 'private') return;
        
        $client = $container->get(Client::class);
        $db = $container->get(Database::class);
        
        // Parse command
        $cmdToken = preg_split('/\s+/', trim($text), 2)[0] ?? '';
        if (strpos($cmdToken, '@') !== false) {
            $cmdToken = explode('@', $cmdToken, 2)[0];
        }
        
        // Check if user is admin
        if (!$this->isAdmin($userId, $chatId, $db, $client)) {
            return;
        }
        
        switch ($cmdToken) {
            case '/pin':
                $this->handlePin($message, $client);
                break;
            case '/unpin':
                $this->handleUnpin($chatId, $client);
                break;
            case '/unpinall':
                $this->handleUnpinAll($chatId, $client);
                break;
        }
    }
    
    private function handlePin(array $message, Client $client): void {
        $chatId = $message['chat']['id'];
        
        if (!isset($message['reply_to_message'])) {
            $client->sendMessage($chatId, "❌ Please reply to a message to pin it.");
            return;
        }
        
        $messageId = $message['reply_to_message']['message_id'];
        
        // Check if notify flag is passed
        $parts = preg_split('/\s+/', $message['text']);
        $notify = !in_array('loud', $parts); // Default is silent unless 'loud' is specified
        
        $result = $client->pinChatMessage($chatId, $messageId, !$notify);
        
        if ($result && ($result['ok'] ?? false)) {
            $client->sendMessage($chatId, "📌 Message pinned successfully!");
        } else {
            $client->sendMessage($chatId, "❌ Failed to pin message. Make sure I have admin rights.");
        }
    }
    
    private function handleUnpin(int $chatId, Client $client): void {
        $result = $client->unpinChatMessage($chatId);
        
        if ($result && ($result['ok'] ?? false)) {
            $client->sendMessage($chatId, "✅ Message unpinned.");
        } else {
            $client->sendMessage($chatId, "❌ Failed to unpin message.");
        }
    }
    
    private function handleUnpinAll(int $chatId, Client $client): void {
        $result = $client->unpinAllChatMessages($chatId);
        
        if ($result && ($result['ok'] ?? false)) {
            $client->sendMessage($chatId, "✅ All messages unpinned.");
        } else {
            $client->sendMessage($chatId, "❌ Failed to unpin messages.");
        }
    }
    
    private function isAdmin(int $userId, int $chatId, Database $db, Client $client): bool {
        $stmt = $db->prepare("SELECT 1 FROM group_owners WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$chatId, $userId]);
        if ($stmt->fetch()) return true;
        
        $result = $client->getChatMember($chatId, $userId);
        if ($result && isset($result['result'])) {
            $status = $result['result']['status'];
            return in_array($status, ['creator', 'administrator']);
        }
        
        return false;
    }
}
