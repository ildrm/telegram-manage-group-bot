<?php
namespace App\Modules;

use App\Interfaces\PluginInterface;
use App\Core\Container;
use App\Database\Database;
use App\Telegram\Client;

class ModerationCommandsModule implements PluginInterface {
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
            return; // Silently ignore if not admin
        }
        
        switch ($cmdToken) {
            case '/ban':
                $this->handleBan($message, $client, $db);
                break;
            case '/unban':
                $this->handleUnban($message, $client, $db);
                break;
            case '/kick':
                $this->handleKick($message, $client, $db);
                break;
            case '/mute':
                $this->handleMute($message, $client, $db);
                break;
            case '/unmute':
                $this->handleUnmute($message, $client, $db);
                break;
            case '/warn':
                $this->handleWarn($message, $client, $db);
                break;
            case '/unwarn':
                $this->handleUnwarn($message, $client, $db);
                break;
            case '/warns':
                $this->handleWarns($message, $client, $db);
                break;
        }
    }
    
    private function handleBan(array $message, Client $client, Database $db): void {
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
        
        $targetId = $this->getTargetUser($message);
        if (!$targetId) {
            $client->sendMessage($chatId, "❌ Please reply to a user to ban them.");
            return;
        }
        
        // Get reason
        $parts = preg_split('/\s+/', $message['text'], 3);
        $reason = $parts[2] ?? 'No reason provided';
        
        // Ban the user
        $result = $client->banChatMember($chatId, $targetId, null, true);
        
        if ($result && ($result['ok'] ?? false)) {
            // Record in database
            $stmt = $db->prepare("INSERT INTO bans (group_id, user_id, banned_by, reason, banned_at) VALUES (?, ?, ?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE banned_by = VALUES(banned_by), reason = VALUES(reason), banned_at = VALUES(banned_at)");
            $stmt->execute([$chatId, $targetId, $userId, $reason, time()]);
            
            $client->sendMessage($chatId, "🚫 <b>User Banned</b>\n\n<b>Reason:</b> " . htmlspecialchars($reason));
        } else {
            $client->sendMessage($chatId, "❌ Failed to ban user. Make sure I have admin rights.");
        }
    }
    
    private function handleUnban(array $message, Client $client, Database $db): void {
        $chatId = $message['chat']['id'];
        
        $targetId = $this->getTargetUser($message);
        if (!$targetId) {
            $client->sendMessage($chatId, "❌ Please reply to a user to unban them.");
            return;
        }
        
        $result = $client->unbanChatMember($chatId, $targetId);
        
        if ($result && ($result['ok'] ?? false)) {
            // Remove from database
            $stmt = $db->prepare("DELETE FROM bans WHERE group_id = ? AND user_id = ?");
            $stmt->execute([$chatId, $targetId]);
            
            $client->sendMessage($chatId, "✅ User has been unbanned.");
        } else {
            $client->sendMessage($chatId, "❌ Failed to unban user.");
        }
    }
    
    private function handleKick(array $message, Client $client, Database $db): void {
        $chatId = $message['chat']['id'];
        
        $targetId = $this->getTargetUser($message);
        if (!$targetId) {
            $client->sendMessage($chatId, "❌ Please reply to a user to kick them.");
            return;
        }
        
        $result = $client->kickChatMember($chatId, $targetId);
        
        if ($result && ($result['ok'] ?? false)) {
            $client->sendMessage($chatId, "👢 User has been kicked.");
        } else {
            $client->sendMessage($chatId, "❌ Failed to kick user.");
        }
    }
    
    private function handleMute(array $message, Client $client, Database $db): void {
        $chatId = $message['chat']['id'];
        
        $targetId = $this->getTargetUser($message);
        if (!$targetId) {
            $client->sendMessage($chatId, "❌ Please reply to a user to mute them.");
            return;
        }
        
        // Parse duration (default: permanent)
        $parts = preg_split('/\s+/', $message['text']);
        $duration = $parts[1] ?? null;
        $untilDate = null;
        
        if ($duration) {
            // Parse duration like "10m", "1h", "1d"
            $untilDate = $this->parseDuration($duration);
        }
        
        $permissions = [
            'can_send_messages' => false,
            'can_send_media_messages' => false,
            'can_send_polls' => false,
            'can_send_other_messages' => false,
            'can_add_web_page_previews' => false,
            'can_change_info' => false,
            'can_invite_users' => false,
            'can_pin_messages' => false
        ];
        
        $result = $client->restrictChatMember($chatId, $targetId, $permissions, $untilDate);
        
        if ($result && ($result['ok'] ?? false)) {
            $msg = "🔇 User has been muted";
            if ($untilDate) {
                $msg .= " until " . date('Y-m-d H:i:s', $untilDate);
            } else {
                $msg .= " permanently";
            }
            $client->sendMessage($chatId, $msg . ".");
        } else {
            $client->sendMessage($chatId, "❌ Failed to mute user.");
        }
    }
    
    private function handleUnmute(array $message, Client $client, Database $db): void {
        $chatId = $message['chat']['id'];
        
        $targetId = $this->getTargetUser($message);
        if (!$targetId) {
            $client->sendMessage($chatId, "❌ Please reply to a user to unmute them.");
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
        
        $result = $client->restrictChatMember($chatId, $targetId, $permissions);
        
        if ($result && ($result['ok'] ?? false)) {
            $client->sendMessage($chatId, "🔊 User has been unmuted.");
        } else {
            $client->sendMessage($chatId, "❌ Failed to unmute user.");
        }
    }
    
    private function handleWarn(array $message, Client $client, Database $db): void {
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
        
        $targetId = $this->getTargetUser($message);
        if (!$targetId) {
            $client->sendMessage($chatId, "❌ Please reply to a user to warn them.");
            return;
        }
        
        // Get reason
        $parts = preg_split('/\s+/', $message['text'], 3);
        $reason = $parts[2] ?? 'No reason provided';
        
        // Add warning
        $stmt = $db->prepare("INSERT INTO warns (group_id, user_id, warned_by, reason, warned_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$chatId, $targetId, $userId, $reason, time()]);
        
        // Count warnings
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM warns WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$chatId, $targetId]);
        $warnCount = $stmt->fetchColumn();
        
        // Get max warns from settings
        $stmt = $db->prepare("SELECT max_warns, warn_action FROM settings WHERE group_id = ?");
        $stmt->execute([$chatId]);
        $settings = $stmt->fetch(\PDO::FETCH_ASSOC);
        $maxWarns = $settings['max_warns'] ?? 3;
        $warnAction = $settings['warn_action'] ?? 'mute';
        
        $remaining = $maxWarns - $warnCount;
        
        if ($remaining > 0) {
            $client->sendMessage($chatId, "⚠️ <b>Warning Issued</b>\n\n<b>Reason:</b> " . htmlspecialchars($reason) . "\n<b>Warnings:</b> {$warnCount}/{$maxWarns}\n<b>Remaining:</b> {$remaining}");
        } else {
            // Execute action
            if ($warnAction === 'ban') {
                $client->banChatMember($chatId, $targetId);
                $action = 'banned';
            } elseif ($warnAction === 'kick') {
                $client->kickChatMember($chatId, $targetId);
                $action = 'kicked';
            } else {
                $permissions = [
                    'can_send_messages' => false,
                    'can_send_media_messages' => false,
                    'can_send_polls' => false,
                    'can_send_other_messages' => false,
                    'can_add_web_page_previews' => false,
                    'can_change_info' => false,
                    'can_invite_users' => false,
                    'can_pin_messages' => false
                ];
                $client->restrictChatMember($chatId, $targetId, $permissions);
                $action = 'muted';
            }
            
            // Clear warnings
            $stmt = $db->prepare("DELETE FROM warns WHERE group_id = ? AND user_id = ?");
            $stmt->execute([$chatId, $targetId]);
            
            $client->sendMessage($chatId, "🚫 <b>Maximum warnings reached!</b>\n\nUser has been {$action}.");
        }
    }
    
    private function handleUnwarn(array $message, Client $client, Database $db): void {
        $chatId = $message['chat']['id'];
        
        $targetId = $this->getTargetUser($message);
        if (!$targetId) {
            $client->sendMessage($chatId, "❌ Please reply to a user to remove their warning.");
            return;
        }
        
        // Remove one warning
        $stmt = $db->prepare("DELETE FROM warns WHERE group_id = ? AND user_id = ? ORDER BY warned_at DESC LIMIT 1");
        $stmt->execute([$chatId, $targetId]);
        
        if ($stmt->rowCount() > 0) {
            // Count remaining
            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM warns WHERE group_id = ? AND user_id = ?");
            $stmt->execute([$chatId, $targetId]);
            $remaining = $stmt->fetchColumn();
            
            $client->sendMessage($chatId, "✅ One warning removed. Remaining warnings: {$remaining}");
        } else {
            $client->sendMessage($chatId, "ℹ️ User has no warnings.");
        }
    }
    
    private function handleWarns(array $message, Client $client, Database $db): void {
        $chatId = $message['chat']['id'];
        
        $targetId = $this->getTargetUser($message);
        if (!$targetId) {
            $client->sendMessage($chatId, "❌ Please reply to a user to check their warnings.");
            return;
        }
        
        // Get warnings
        $stmt = $db->prepare("SELECT reason, warned_at FROM warns WHERE group_id = ? AND user_id = ? ORDER BY warned_at DESC");
        $stmt->execute([$chatId, $targetId]);
        $warns = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        if (empty($warns)) {
            $client->sendMessage($chatId, "✅ This user has no warnings.");
            return;
        }
        
        $msg = "⚠️ <b>Warning History</b>\n\n";
        foreach ($warns as $i => $warn) {
            $date = date('Y-m-d H:i', $warn['warned_at']);
            $msg .= ($i + 1) . ". " . htmlspecialchars($warn['reason']) . "\n   <i>({$date})</i>\n\n";
        }
        $msg .= "<b>Total:</b> " . count($warns);
        
        $client->sendMessage($chatId, $msg);
    }
    
    private function getTargetUser(array $message): ?int {
        if (isset($message['reply_to_message']['from']['id'])) {
            return $message['reply_to_message']['from']['id'];
        }
        return null;
    }
    
    private function isAdmin(int $userId, int $chatId, Database $db, Client $client): bool {
        // Check if user is group owner in database
        $stmt = $db->prepare("SELECT 1 FROM group_owners WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$chatId, $userId]);
        if ($stmt->fetch()) return true;
        
        // Check via Telegram API
        $result = $client->getChatMember($chatId, $userId);
        if ($result && isset($result['result'])) {
            $status = $result['result']['status'];
            return in_array($status, ['creator', 'administrator']);
        }
        
        return false;
    }
    
    private function parseDuration(?string $duration): ?int {
        if (!$duration) return null;
        
        preg_match('/^(\d+)([mhd])$/', $duration, $matches);
        if (empty($matches)) return null;
        
        $value = (int)$matches[1];
        $unit = $matches[2];
        
        $multiplier = [
            'm' => 60,
            'h' => 3600,
            'd' => 86400
        ];
        
        return time() + ($value * ($multiplier[$unit] ?? 60));
    }
}
