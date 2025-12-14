<?php
namespace App\Modules;

use App\Interfaces\PluginInterface;
use App\Core\Container;
use App\Telegram\Client;
use App\Database\Database;

class CoreModule implements PluginInterface {
    public function register(Container $container): void {
        // Register services specific to Core if needed
    }

    public function boot(Container $container): void {
        // Boot logic
    }

    public function getListeners(): array {
        return [
            'update.received' => 'handleUpdate'
        ];
    }

    public function handleUpdate(array $update, Container $container): void {
        $client = $container->get(Client::class);
        
        // 1. Handle MyChatMember (Bot added to group)
        if (isset($update['my_chat_member'])) {
            $this->handleMyChatMember($update['my_chat_member'], $container);
            return;
        }

        // 2. Handle Message
        if (isset($update['message'])) {
            $message = $update['message'];
            $text = $message['text'] ?? '';
            $chatId = $message['chat']['id'];
            $userId = $message['from']['id'];

            if ($text === '/start') {
                $client->sendMessage($chatId, "👋 Hello! I am the Modular Group Manager Bot v2.0.\n\nCreated with the new architecture.");
            }
        }
    }
    
    private function handleMyChatMember(array $data, Container $container): void {
        // Porting logic from legacy: handleMyChatMember
        $chat = $data['chat'];
        $newStatus = $data['new_chat_member']['status'];
        $userId = $data['from']['id'];
        
        if (!in_array($chat['type'], ['group', 'supergroup'])) {
            return;
        }
        
        $db = $container->get(Database::class);
        $client = $container->get(Client::class);
        $groupId = $chat['id'];
        
        if (in_array($newStatus, ['administrator', 'member'])) {
            // Register group
            $db->replace('groups', [
                'group_id' => $groupId,
                'title' => $chat['title'] ?? 'Unknown',
                'username' => $chat['username'] ?? '',
                'active' => 1
            ]);
            
            // Register Owner
            $db->insertIgnore('group_owners', [
                'group_id' => $groupId,
                'user_id' => $userId
            ]);
            
            // Notify User
            $client->sendMessage($userId, "✅ <b>Group Registered!</b>\n\nTitle: " . ($chat['title'] ?? 'Unknown'));
        }
    }
}
