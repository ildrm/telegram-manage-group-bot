<?php
namespace App\Modules;

use App\Interfaces\PluginInterface;
use App\Core\Container;
use App\Database\Database;
use App\Telegram\Client;

class InfoCommandsModule implements PluginInterface {
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
        $chatType = $message['chat']['type'];
        
        $client = $container->get(Client::class);
        $db = $container->get(Database::class);
        
        // Parse command
        $cmdToken = preg_split('/\s+/', trim($text), 2)[0] ?? '';
        if (strpos($cmdToken, '@') !== false) {
            $cmdToken = explode('@', $cmdToken, 2)[0];
        }
        
        switch ($cmdToken) {
            case '/stats':
                if ($chatType !== 'private') {
                    $this->handleStats($chatId, $client, $db);
                }
                break;
            case '/members':
                if ($chatType !== 'private') {
                    $this->handleMembers($chatId, $client);
                }
                break;
            case '/info':
                if ($chatType !== 'private') {
                    $this->handleInfo($chatId, $client, $db);
                }
                break;
            case '/rules':
                if ($chatType !== 'private') {
                    $this->handleRules($chatId, $client, $db);
                }
                break;
            case '/admins':
                if ($chatType !== 'private') {
                    $this->handleAdmins($chatId, $client);
                }
                break;
        }
    }
    
    private function handleStats(int $chatId, Client $client, Database $db): void {
        // Get today's stats
        $today = date('Y-m-d');
        $stmt = $db->prepare("SELECT event_type, count FROM stats WHERE group_id = ? AND event_date = ?");
        $stmt->execute([$chatId, $today]);
        $todayStats = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        
        // Get week stats
        $weekAgo = date('Y-m-d', strtotime('-7 days'));
        $stmt = $db->prepare("SELECT event_type, SUM(count) as total FROM stats WHERE group_id = ? AND event_date >= ? GROUP BY event_type");
        $stmt->execute([$chatId, $weekAgo]);
        $weekStats = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        
        // Get all-time stats
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM warns WHERE group_id = ?");
        $stmt->execute([$chatId]);
        $totalWarns = $stmt->fetchColumn();
        
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM bans WHERE group_id = ?");
        $stmt->execute([$chatId]);
        $totalBans = $stmt->fetchColumn();
        
        // Get member count
        $result = $client->getChatMembersCount($chatId);
        $memberCount = $result['result'] ?? 'N/A';
        
        $msg = "📊 <b>Group Statistics</b>\n\n";
        $msg .= "👥 <b>Members:</b> {$memberCount}\n\n";
        $msg .= "📈 <b>Today:</b>\n";
        $msg .= "  Joins: " . ($todayStats['join'] ?? 0) . "\n";
        $msg .= "  Messages: " . ($todayStats['message'] ?? 0) . "\n\n";
        $msg .= "📊 <b>Last 7 Days:</b>\n";
        $msg .= "  Joins: " . ($weekStats['join'] ?? 0) . "\n";
        $msg .= "  Messages: " . ($weekStats['message'] ?? 0) . "\n\n";
        $msg .= "⚠️ <b>Moderation:</b>\n";
        $msg .= "  Total Warnings: {$totalWarns}\n";
        $msg .= "  Total Bans: {$totalBans}";
        
        $client->sendMessage($chatId, $msg);
    }
    
    private function handleMembers(int $chatId, Client $client): void {
        $result = $client->getChatMembersCount($chatId);
        
        if ($result && isset($result['result'])) {
            $count = $result['result'];
            $client->sendMessage($chatId, "👥 <b>Group Members:</b> {$count}");
        } else {
            $client->sendMessage($chatId, "❌ Failed to get member count.");
        }
    }
    
    private function handleInfo(int $chatId, Client $client, Database $db): void {
        $result = $client->getChat($chatId);
        
        if (!$result || !isset($result['result'])) {
            $client->sendMessage($chatId, "❌ Failed to get group info.");
            return;
        }
        
        $chat = $result['result'];
        $title = $chat['title'] ?? 'N/A';
        $username = isset($chat['username']) ? '@' . $chat['username'] : 'No username';
        $description = $chat['description'] ?? 'No description';
        
        $memberResult = $client->getChatMembersCount($chatId);
        $memberCount = $memberResult['result'] ?? 'N/A';
        
        // Get settings
        $stmt = $db->prepare("SELECT * FROM settings WHERE group_id = ?");
        $stmt->execute([$chatId]);
        $settings = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $msg = "ℹ️ <b>Group Information</b>\n\n";
        $msg .= "📌 <b>Title:</b> {$title}\n";
        $msg .= "🔗 <b>Username:</b> {$username}\n";
        $msg .= "👥 <b>Members:</b> {$memberCount}\n\n";
        $msg .= "📝 <b>Description:</b>\n{$description}\n\n";
        
        if ($settings) {
            $msg .= "⚙️ <b>Settings:</b>\n";
            $msg .= "  Welcome: " . ($settings['welcome_enabled'] ? '✅' : '❌') . "\n";
            $msg .= "  CAPTCHA: " . ($settings['captcha_enabled'] ? '✅' : '❌') . "\n";
            $msg .= "  Anti-flood: " . ($settings['antiflood_enabled'] ? '✅' : '❌') . "\n";
            $msg .= "  Anti-link: " . ($settings['antilink_enabled'] ? '✅' : '❌') . "\n";
            $msg .= "  Anti-bot: " . ($settings['antibot_enabled'] ? '✅' : '❌') . "\n";
        }
        
        $client->sendMessage($chatId, $msg);
    }
    
    private function handleRules(int $chatId, Client $client, Database $db): void {
        $stmt = $db->prepare("SELECT rules_text FROM settings WHERE group_id = ?");
        $stmt->execute([$chatId]);
        $rules = $stmt->fetchColumn();
        
        if (empty($rules)) {
            $client->sendMessage($chatId, "📜 <b>Group Rules</b>\n\nNo rules have been set yet.\nAdmins can set rules using /setrules");
        } else {
            $msg = "📜 <b>Group Rules</b>\n\n" . htmlspecialchars($rules);
            
            $keyboard = [
                'inline_keyboard' => [[
                    ['text' => '✅ I agree', 'callback_data' => 'rules:agree']
                ]]
            ];
            
            $client->sendMessage($chatId, $msg, $keyboard);
        }
    }
    
    private function handleAdmins(int $chatId, Client $client): void {
        $result = $client->getChatAdministrators($chatId);
        
        if (!$result || !isset($result['result'])) {
            $client->sendMessage($chatId, "❌ Failed to get admin list.");
            return;
        }
        
        $admins = $result['result'];
        $msg = "👑 <b>Group Administrators</b>\n\n";
        
        foreach ($admins as $admin) {
            $user = $admin['user'];
            $status = $admin['status'];
            $name = $user['first_name'];
            $username = isset($user['username']) ? '@' . $user['username'] : '';
            
            $icon = $status === 'creator' ? '👑' : '⭐';
            $msg .= "{$icon} {$name} {$username}\n";
        }
        
        $client->sendMessage($chatId, $msg);
    }
}
