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
        $client = $container->get(Client::class);
        $db = $container->get(Database::class);

        // Handle Callbacks
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query'], $container);
            return;
        }

        if (!isset($update['message']['text'])) return;

        $message = $update['message'];
        $text = $message['text'];
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];

        // Telegram may send /cmd@BotUsername in groups
        $cmdToken = preg_split('/\s+/', trim($text), 2)[0] ?? '';
        if (strpos($cmdToken, '@') !== false) {
            $cmdToken = explode('@', $cmdToken, 2)[0];
        }
        
        // Command: /settings (In Group)
        if ($cmdToken === '/settings') {
            if ($message['chat']['type'] === 'private') {
                $client->sendMessage($chatId, "⚠️ This command is for groups.");
                return;
            }
            if (!$this->isOwner($userId, $chatId, $db)) {
                 $client->sendMessage($chatId, "⛔ Access denied.");
                 return;
            }
            // Show settings panel
            $this->showSettingsPanel($chatId, $message['message_id'], $chatId, $client, $db); // Using chatId as groupId
            return;
        }

        // Command: /promote (reply-based)
        if ($cmdToken === '/promote') {
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
            return;
        }
        
        // Command: /demote (reply-based)
        if ($cmdToken === '/demote') {
             if (!$this->isOwner($userId, $chatId, $db)) {
                $client->sendMessage($chatId, "⛔ Access denied.");
                return;
            }
            
            $targetId = $this->getTargetUser($message);
            if ($targetId) {
                $this->removeRole($chatId, $targetId, $db);
                $client->sendMessage($chatId, "✅ User demoted.");
            }
            return;
        }
    }
    
    private function handleCallback(array $callback, Container $container): void {
        $data = $callback['data'];
        $chatId = $callback['message']['chat']['id'];
        $messageId = $callback['message']['message_id'];
        $userId = $callback['from']['id'];
        
        $client = $container->get(Client::class);
        $db = $container->get(Database::class);
        
        if (strpos($data, 'settings:') === 0) {
             $parts = explode(':', $data);
             $action = $parts[1];
             $groupId = (int)$parts[2];
             
             // Verify Owner
             if (!$this->isOwner($userId, $groupId, $db)) {
                 // Alert
                 // $client->answerCallbackQuery...
                 return;
             }
             
             if ($action === 'welcome') {
                 // Toggle logic or sub-menu
                 $this->showWelcomeSettings($chatId, $messageId, $groupId, $client, $db);
             } elseif ($action === 'mod') {
                 $this->showModerationSettings($chatId, $messageId, $groupId, $client, $db);
             } elseif ($action === 'toggle') {
                 $setting = $parts[3];
                 $this->toggleSetting($groupId, $setting, $db);
                 // Refresh current view (assuming we came from Mod settings for now)
                  $this->showModerationSettings($chatId, $messageId, $groupId, $client, $db);
             }
        }
    }
    
    private function showSettingsPanel(int $chatId, int $messageId, int $groupId, Client $client, Database $db): void {
        // This is redundancy of showGroupDashboard from CoreModule but for in-group usage
        // Let's reuse showGroupDashboard concept or link to PM
        $client->sendMessage($chatId, "⚙️ Please manage settings in Private Chat for security.", [
             'inline_keyboard' => [[['text' => 'Go to Dashboard', 'url' => "https://t.me/" . "YourBotUsername" . "?start=dash"]]]
        ]);
    }
    
    private function showWelcomeSettings(int $chatId, int $messageId, int $groupId, Client $client, Database $db): void {
         $client->editMessageText($chatId, $messageId, "📝 <b>Welcome Settings</b>\n\nTo change message, send:\n<code>/setwelcome Hello {name}!</code>", [
             'inline_keyboard' => [[['text' => '🔙 Back', 'callback_data' => "dash:manage:$groupId"]]]
         ]);
    }
    
    private function showModerationSettings(int $chatId, int $messageId, int $groupId, Client $client, Database $db): void {
        // Fetch current settings
        $stmt = $db->prepare("SELECT * FROM settings WHERE group_id = ?");
        $stmt->execute([$groupId]);
        $settings = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$settings) {
            // Need init settings logic or handle empty
            $settings = [];
        }
        
        $btn = function($key, $label) use ($settings, $groupId) {
            $val = $settings[$key] ?? 0;
            $icon = $val ? '✅' : '❌';
            return ['text' => "$icon $label", 'callback_data' => "settings:toggle:$groupId:$key"];
        };
        
        $keyboard = [
            'inline_keyboard' => [
                [$btn('antiflood_enabled', 'Anti-Flood'), $btn('antibot_enabled', 'Anti-Bot')],
                [$btn('antilink_enabled', 'Anti-Link'), $btn('captcha_enabled', 'CAPTCHA')],
                [['text' => '🔙 Back', 'callback_data' => "dash:manage:$groupId"]]
            ]
        ];
        
        $client->editMessageText($chatId, $messageId, "🛡 <b>Moderation Settings</b>\n\nClick to toggle:", $keyboard);
    }
    
    private function toggleSetting(int $groupId, string $key, Database $db): void {
        // Safe list keys
        $allowed = ['antiflood_enabled', 'antibot_enabled', 'antilink_enabled', 'captcha_enabled'];
        if (!in_array($key, $allowed)) return;
        
        // Retrieve current
         $stmt = $db->prepare("SELECT $key FROM settings WHERE group_id = ?");
         $stmt->execute([$groupId]);
         $curr = $stmt->fetchColumn();
         
         $new = $curr ? 0 : 1;
         
         // Upsert logic needed since row might not exist
         // Using simplified update/insert
         $check = $db->prepare("SELECT 1 FROM settings WHERE group_id = ?");
         $check->execute([$groupId]);
         
         if ($check->fetchColumn()) {
             $upd = $db->prepare("UPDATE settings SET $key = ? WHERE group_id = ?");
             $upd->execute([$new, $groupId]);
         } else {
             $ins = $db->prepare("INSERT INTO settings (group_id, $key) VALUES (?, ?)");
             $ins->execute([$groupId, $new]);
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
        $stmt = $db->prepare("SELECT 1 FROM group_owners WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$groupId, $userId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (bool)$result;
    }
    
    private function setRole(int $groupId, int $userId, string $role, Database $db): void {
        $stmt = $db->prepare("INSERT INTO group_owners (group_id, user_id, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE role = VALUES(role)");
        $stmt->execute([$groupId, $userId, $role]);
    }
    
    private function removeRole(int $groupId, int $userId, Database $db): void {
        $stmt = $db->prepare("DELETE FROM group_owners WHERE group_id = ? AND user_id = ? AND role != 'owner'");
        $stmt->execute([$groupId, $userId]);
    }
}
