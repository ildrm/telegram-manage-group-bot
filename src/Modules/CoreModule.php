<?php
namespace App\Modules;

use App\Interfaces\PluginInterface;
use App\Core\Container;
use App\Telegram\Client;
use App\Database\Database;

class CoreModule implements PluginInterface {
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
        $db = $container->get(Database::class); // Ensure DB is available

        // 1. Handle MyChatMember (Bot added/removed from group)
        if (isset($update['my_chat_member'])) {
            $this->handleMyChatMember($update['my_chat_member'], $container);
            return;
        }

        // 2. Handle Callback Queries (Dashboard)
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query'], $container);
            return;
        }

        // 3. Handle Message
        if (isset($update['message']['text'])) {
            $message = $update['message'];
            $text = $message['text'];
            $chatId = $message['chat']['id'];
            $userId = $message['from']['id'];

            // Telegram may send /cmd@BotUsername in groups
            $cmdToken = preg_split('/\s+/', trim($text), 2)[0] ?? '';
            if (strpos($cmdToken, '@') !== false) {
                $cmdToken = explode('@', $cmdToken, 2)[0];
            }

            if ($cmdToken === '/start') {
                $this->showMainMenu($chatId, $userId, $client);
            }
            elseif ($cmdToken === '/help') {
                $this->showHelp($chatId, $client);
            }
            elseif ($cmdToken === '/mygroups') {
                $this->showGroupList($chatId, $userId, $client, $db);
            }
        }
    }

    private function showMainMenu(int $chatId, int $userId, Client $client): void {
        $msg = "👋 <b>Welcome to Modular Group Manager!</b>\n\n";
        $msg .= "I can help you manage your Telegram groups with ease.\n\n";
        $msg .= "👇 <b>Choose an option:</b>";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📂 My Groups', 'callback_data' => 'dash:groups'],
                    ['text' => '❓ Help', 'callback_data' => 'dash:help']
                ],
                [
                    ['text' => '➕ Add to Group', 'url' => 'https://t.me/' . $this->getBotUsername($client) . '?startgroup=true']
                ]
            ]
        ];

        $client->sendMessage($chatId, $msg, $keyboard);
    }
    
    private function showHelp(int $chatId, Client $client): void {
        $msg = "📚 <b>Command List</b>\n\n";
        $msg .= "<b>Core:</b>\n";
        $msg .= "/start - Main Menu\n";
        $msg .= "/mygroups - List your groups\n";
        $msg .= "/help - Show this help\n\n";
        
        $msg .= "<b>Utilities:</b>\n";
        $msg .= "/weather [City] - Check weather\n";
        $msg .= "/price - Crypto prices\n\n";
        
        $msg .= "<b>Admin:</b>\n";
        $msg .= "/settings - Group Settings (in group)\n";
        $msg .= "/promote - Promote user\n";
        $msg .= "/demote - Demote user\n";
        $msg .= "/warn - Warn user\n";
        $msg .= "/ban - Ban user\n";
        
        $client->sendMessage($chatId, $msg);
    }

    private function showGroupList(int $chatId, int $userId, Client $client, Database $db): void {
        // Fetch groups where user is owner
        $stmt = $db->prepare("
            SELECT g.group_id, g.title 
            FROM groups g 
            JOIN group_owners o ON g.group_id = o.group_id 
            WHERE o.user_id = ? AND g.active = 1
        ");
        $stmt->execute([$userId]);
        $groups = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($groups)) {
            $client->sendMessage($chatId, "You don't manage any groups yet. Add me to a group first!");
            return;
        }

        $keyboard = ['inline_keyboard' => []];
        foreach ($groups as $group) {
            $keyboard['inline_keyboard'][] = [
                ['text' => $group['title'], 'callback_data' => 'dash:manage:' . $group['group_id']]
            ];
        }
        $keyboard['inline_keyboard'][] = [['text' => '🔙 Main Menu', 'callback_data' => 'dash:main']];

        $client->sendMessage($chatId, "📂 <b>Select a group to manage:</b>", $keyboard);
    }

    private function handleCallback(array $callback, Container $container): void {
        $data = $callback['data'];
        $chatId = $callback['message']['chat']['id'];
        $userId = $callback['from']['id'];
        $messageId = $callback['message']['message_id'];
        
        $client = $container->get(Client::class);
        $db = $container->get(Database::class);

        if ($data === 'dash:main') {
            $this->editToMainMenu($chatId, $messageId, $userId, $client);
        } elseif ($data === 'dash:groups') {
            $this->editToGroupList($chatId, $messageId, $userId, $client, $db);
        } elseif ($data === 'dash:help') {
             // Just send help as new message or edit
             $this->showHelp($chatId, $client);
        } elseif (strpos($data, 'dash:manage:') === 0) {
            $groupId = substr($data, 12);
            $this->showGroupDashboard($chatId, $messageId, (int)$groupId, $client, $db);
        }
    }
    
    private function editToMainMenu(int $chatId, int $messageId, int $userId, Client $client): void {
        $msg = "👋 <b>Welcome to Modular Group Manager!</b>\n👇 <b>Choose an option:</b>";
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📂 My Groups', 'callback_data' => 'dash:groups'],
                    ['text' => '❓ Help', 'callback_data' => 'dash:help']
                ],
                 [
                    ['text' => '➕ Add to Group', 'url' => 'https://t.me/' . $this->getBotUsername($client) . '?startgroup=true']
                ]
            ]
        ];
        $client->editMessageText($chatId, $messageId, $msg, $keyboard);
    }

    private function editToGroupList(int $chatId, int $messageId, int $userId, Client $client, Database $db): void {
         $stmt = $db->prepare("
            SELECT g.group_id, g.title 
            FROM groups g 
            JOIN group_owners o ON g.group_id = o.group_id 
            WHERE o.user_id = ? AND g.active = 1
        ");
        $stmt->execute([$userId]);
        $groups = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($groups)) {
            $client->editMessageText($chatId, $messageId, "You don't manage any groups yet.");
            return;
        }

        $keyboard = ['inline_keyboard' => []];
        foreach ($groups as $group) {
            $keyboard['inline_keyboard'][] = [
                ['text' => $group['title'], 'callback_data' => 'dash:manage:' . $group['group_id']]
            ];
        }
        $keyboard['inline_keyboard'][] = [['text' => '🔙 Main Menu', 'callback_data' => 'dash:main']];

        $client->editMessageText($chatId, $messageId, "📂 <b>Select a group to manage:</b>", $keyboard);
    }
    
    private function showGroupDashboard(int $chatId, int $messageId, int $groupId, Client $client, Database $db): void {
        // Fetch group name
        $stmt = $db->prepare("SELECT title FROM groups WHERE group_id = ?");
        $stmt->execute([$groupId]);
        $group = $stmt->fetch(\PDO::FETCH_ASSOC);
        $title = $group['title'] ?? 'Unknown Group';

        $msg = "⚙️ <b>Managing: $title</b>\n\nWhat would you like to configure?";
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🛡 Moderation', 'callback_data' => "settings:mod:$groupId"],
                    ['text' => '📝 Welcome', 'callback_data' => "settings:welcome:$groupId"]
                ],
                [
                    ['text' => '📊 Stats', 'callback_data' => "stats:view:$groupId"],
                    ['text' => '🔙 Back', 'callback_data' => 'dash:groups']
                ]
            ]
        ];
        
        $client->editMessageText($chatId, $messageId, $msg, $keyboard);
    }

    private function handleMyChatMember(array $data, Container $container): void {
        $chat = $data['chat'] ?? [];
        $newStatus = $data['new_chat_member']['status'] ?? '';
        $oldStatus = $data['old_chat_member']['status'] ?? '';
        $userId = $data['from']['id'] ?? 0;
        
        if (!in_array($chat['type'] ?? '', ['group', 'supergroup'])) {
            return;
        }
        
        $db = $container->get(Database::class);
        $client = $container->get(Client::class);
        $auth = $container->get(\App\Services\AuthorizationService::class);
        $groupId = $chat['id'] ?? 0;
        
        if ($groupId <= 0 || $userId <= 0) {
            return;
        }

        // Bot was added or promoted to admin
        if (in_array($newStatus, ['administrator', 'member'])) {
            // Register/update group
            $group = \App\Models\Group::findOrCreate($db, $chat);
            
            // Register owner (person who added the bot)
            $auth->addOwner($groupId, $userId);
            
            // Initialize settings
            $settings = $container->get(\App\Services\SettingsService::class);
            $settings->getSettings($groupId); // This creates defaults if needed
            
            // Notify owner
            try {
                $title = htmlspecialchars($chat['title'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
                $client->sendMessage($userId, "✅ <b>Group Registered!</b>\n\n" .
                    "Group: {$title}\n" .
                    "You can now manage this group. Use /start to open your dashboard.");
            } catch (\Exception $e) {
                error_log("Failed to notify owner about group registration: " . $e->getMessage());
            }
        } 
        // Bot was removed or demoted
        elseif (in_array($newStatus, ['left', 'kicked']) && in_array($oldStatus, ['administrator', 'member'])) {
            $group = \App\Models\Group::find($db, $groupId);
            if ($group) {
                $group->deactivate();
            }
        }
    }
    
    private function getBotUsername(Client $client): string {
        static $username = null;
        
        if ($username === null) {
            try {
                $me = $client->getMe();
                if ($me && isset($me['result']['username'])) {
                    $username = $me['result']['username'];
                } else {
                    $username = 'YourBot';
                }
            } catch (\Exception $e) {
                $username = 'YourBot';
            }
        }
        
        return $username;
    }
}
