<?php
namespace App\Modules;

use App\Interfaces\PluginInterface;
use App\Core\Container;
use App\Database\Database;
use App\Telegram\Client;

class SettingsCommandsModule implements PluginInterface {
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
        $parts = preg_split('/\s+/', trim($text), 2);
        $cmdToken = $parts[0] ?? '';
        $args = $parts[1] ?? '';
        
        if (strpos($cmdToken, '@') !== false) {
            $cmdToken = explode('@', $cmdToken, 2)[0];
        }
        
        // Check if user is admin
        if (!$this->isAdmin($userId, $chatId, $db, $client)) {
            return; // Silently ignore if not admin
        }
        
        switch ($cmdToken) {
            case '/setwelcome':
                $this->handleSetWelcome($chatId, $args, $client, $db);
                break;
            case '/setgoodbye':
                $this->handleSetGoodbye($chatId, $args, $client, $db);
                break;
            case '/setrules':
                $this->handleSetRules($chatId, $args, $client, $db);
                break;
            case '/lock':
                $this->handleLock($chatId, $args, $client, $db);
                break;
            case '/unlock':
                $this->handleUnlock($chatId, $args, $client, $db);
                break;
            case '/antiflood':
                $this->handleAntiflood($chatId, $args, $client, $db);
                break;
            case '/antilink':
                $this->handleAntilink($chatId, $args, $client, $db);
                break;
            case '/antibot':
                $this->handleAntibot($chatId, $args, $client, $db);
                break;
            case '/setlink':
                $this->handleSetLink($chatId, $args, $client, $db);
                break;
        }
    }
    
    private function handleSetWelcome(int $chatId, string $message, Client $client, Database $db): void {
        if (empty($message)) {
            $client->sendMessage($chatId, "❌ Usage: /setwelcome <message>\n\nVariables:\n{name} - User's name\n{username} - Username\n{group} - Group name");
            return;
        }
        
        // Ensure settings exist
        $this->ensureSettings($chatId, $db);
        
        $stmt = $db->prepare("UPDATE settings SET welcome_enabled = 1, welcome_message = ? WHERE group_id = ?");
        $stmt->execute([$message, $chatId]);
        
        $client->sendMessage($chatId, "✅ Welcome message updated!");
    }
    
    private function handleSetGoodbye(int $chatId, string $message, Client $client, Database $db): void {
        if (empty($message)) {
            $client->sendMessage($chatId, "❌ Usage: /setgoodbye <message>\n\nVariables:\n{name} - User's name\n{username} - Username");
            return;
        }
        
        $this->ensureSettings($chatId, $db);
        
        $stmt = $db->prepare("UPDATE settings SET goodbye_enabled = 1, goodbye_message = ? WHERE group_id = ?");
        $stmt->execute([$message, $chatId]);
        
        $client->sendMessage($chatId, "✅ Goodbye message updated!");
    }
    
    private function handleSetRules(int $chatId, string $rules, Client $client, Database $db): void {
        if (empty($rules)) {
            $client->sendMessage($chatId, "❌ Usage: /setrules <rules text>");
            return;
        }
        
        $this->ensureSettings($chatId, $db);
        
        $stmt = $db->prepare("UPDATE settings SET rules_text = ? WHERE group_id = ?");
        $stmt->execute([$rules, $chatId]);
        
        $client->sendMessage($chatId, "✅ Group rules updated!");
    }
    
    private function handleLock(int $chatId, string $args, Client $client, Database $db): void {
        $lockTypes = ['messages', 'media', 'stickers', 'links', 'polls', 'invites'];
        
        if (empty($args) || !in_array($args, $lockTypes)) {
            $client->sendMessage($chatId, "❌ Usage: /lock <type>\n\nTypes: " . implode(', ', $lockTypes));
            return;
        }
        
        $permissions = [
            'can_send_messages' => $args !== 'messages',
            'can_send_media_messages' => $args !== 'media',
            'can_send_polls' => $args !== 'polls',
            'can_send_other_messages' => $args !== 'stickers',
            'can_add_web_page_previews' => $args !== 'links',
            'can_change_info' => false,
            'can_invite_users' => $args !== 'invites',
            'can_pin_messages' => false
        ];
        
        $result = $client->setChatPermissions($chatId, $permissions);
        
        if ($result && ($result['ok'] ?? false)) {
            $client->sendMessage($chatId, "🔒 Locked: {$args}");
        } else {
            $client->sendMessage($chatId, "❌ Failed to lock. Make sure I have admin rights.");
        }
    }
    
    private function handleUnlock(int $chatId, string $args, Client $client, Database $db): void {
        $lockTypes = ['messages', 'media', 'stickers', 'links', 'polls', 'invites'];
        
        if (empty($args) || !in_array($args, $lockTypes)) {
            $client->sendMessage($chatId, "❌ Usage: /unlock <type>\n\nTypes: " . implode(', ', $lockTypes));
            return;
        }
        
        $permissions = [
            'can_send_messages' => true,
            'can_send_media_messages' => true,
            'can_send_polls' => true,
            'can_send_other_messages' => true,
            'can_add_web_page_previews' => true,
            'can_change_info' => false,
            'can_invite_users' => true,
            'can_pin_messages' => false
        ];
        
        $result = $client->setChatPermissions($chatId, $permissions);
        
        if ($result && ($result['ok'] ?? false)) {
            $client->sendMessage($chatId, "🔓 Unlocked: {$args}");
        } else {
            $client->sendMessage($chatId, "❌ Failed to unlock.");
        }
    }
    
    private function handleAntiflood(int $chatId, string $args, Client $client, Database $db): void {
        if ($args === 'on') {
            $this->ensureSettings($chatId, $db);
            $stmt = $db->prepare("UPDATE settings SET antiflood_enabled = 1 WHERE group_id = ?");
            $stmt->execute([$chatId]);
            $client->sendMessage($chatId, "✅ Anti-flood enabled");
        } elseif ($args === 'off') {
            $stmt = $db->prepare("UPDATE settings SET antiflood_enabled = 0 WHERE group_id = ?");
            $stmt->execute([$chatId]);
            $client->sendMessage($chatId, "✅ Anti-flood disabled");
        } else {
            $client->sendMessage($chatId, "❌ Usage: /antiflood <on|off>");
        }
    }
    
    private function handleAntilink(int $chatId, string $args, Client $client, Database $db): void {
        if ($args === 'on') {
            $this->ensureSettings($chatId, $db);
            $stmt = $db->prepare("UPDATE settings SET antilink_enabled = 1 WHERE group_id = ?");
            $stmt->execute([$chatId]);
            $client->sendMessage($chatId, "✅ Anti-link enabled");
        } elseif ($args === 'off') {
            $stmt = $db->prepare("UPDATE settings SET antilink_enabled = 0 WHERE group_id = ?");
            $stmt->execute([$chatId]);
            $client->sendMessage($chatId, "✅ Anti-link disabled");
        } else {
            $client->sendMessage($chatId, "❌ Usage: /antilink <on|off>");
        }
    }
    
    private function handleAntibot(int $chatId, string $args, Client $client, Database $db): void {
        if ($args === 'on') {
            $this->ensureSettings($chatId, $db);
            $stmt = $db->prepare("UPDATE settings SET antibot_enabled = 1 WHERE group_id = ?");
            $stmt->execute([$chatId]);
            $client->sendMessage($chatId, "✅ Anti-bot enabled");
        } elseif ($args === 'off') {
            $stmt = $db->prepare("UPDATE settings SET antibot_enabled = 0 WHERE group_id = ?");
            $stmt->execute([$chatId]);
            $client->sendMessage($chatId, "✅ Anti-bot disabled");
        } else {
            $client->sendMessage($chatId, "❌ Usage: /antibot <on|off>");
        }
    }
    
    private function handleSetLink(int $chatId, string $args, Client $client, Database $db): void {
        $result = $client->exportChatInviteLink($chatId);
        
        if ($result && isset($result['result'])) {
            $link = $result['result'];
            $client->sendMessage($chatId, "🔗 <b>Group Invite Link:</b>\n\n<code>{$link}</code>");
        } else {
            $client->sendMessage($chatId, "❌ Failed to generate invite link. Make sure I have admin rights.");
        }
    }
    
    private function ensureSettings(int $chatId, Database $db): void {
        $stmt = $db->prepare("SELECT 1 FROM settings WHERE group_id = ?");
        $stmt->execute([$chatId]);
        
        if (!$stmt->fetch()) {
            $stmt = $db->prepare("INSERT INTO settings (group_id) VALUES (?)");
            $stmt->execute([$chatId]);
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
