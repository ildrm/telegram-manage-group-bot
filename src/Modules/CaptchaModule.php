<?php
namespace App\Modules;

use App\Interfaces\PluginInterface;
use App\Core\Container;
use App\Database\Database;
use App\Telegram\Client;

class CaptchaModule implements PluginInterface {
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

        // 1. New Member -> Restrict & Send Captcha
        if (isset($update['message']['new_chat_members'])) {
            $chatId = $update['message']['chat']['id'];
            
            foreach ($update['message']['new_chat_members'] as $user) {
                if ($user['is_bot']) continue; // Skip bots

                // Restrict
                $client->request('restrictChatMember', [
                    'chat_id' => $chatId,
                    'user_id' => $user['id'],
                    'permissions' => json_encode(['can_send_messages' => false])
                ]);

                // Send Button
                $userId = $user['id'];
                $keyboard = [
                    'inline_keyboard' => [[
                        ['text' => '🤖 Click to Verify', 'callback_data' => "captcha:verify:$userId"]
                    ]]
                ];
                
                $sent = $client->sendMessage($chatId, 
                    "Welcome {$user['first_name']}! Please verify you are human.", 
                    $keyboard
                );
                
                // Log session (to DB)
                // For simplicity in this demo, we assume stateless verification via callback payload checking
                // But in production we should store msg_id to delete it later
            }
            return;
        }

        // 2. Callback -> Verify
        if (isset($update['callback_query'])) {
            $data = $update['callback_query']['data'];
            $fromId = $update['callback_query']['from']['id'];
            
            if (strpos($data, 'captcha:verify:') === 0) {
                $parts = explode(':', $data);
                $targetId = (int)$parts[2];
                $queryId = $update['callback_query']['id'];
                
                if ($fromId !== $targetId) {
                    $client->request('answerCallbackQuery', [
                        'callback_query_id' => $queryId,
                        'text' => "This is not for you!",
                        'show_alert' => true
                    ]);
                    return;
                }

                $chatId = $update['callback_query']['message']['chat']['id'];
                
                // Unrestrict
                $client->request('restrictChatMember', [
                    'chat_id' => $chatId,
                    'user_id' => $targetId,
                    'permissions' => json_encode([
                        'can_send_messages' => true,
                        'can_send_media_messages' => true,
                        // ... restore other perms
                    ])
                ]);

                $client->request('answerCallbackQuery', [
                    'callback_query_id' => $queryId,
                    'text' => "✅ Verified!"
                ]);
                
                $client->request('deleteMessage', [
                    'chat_id' => $chatId,
                    'message_id' => $update['callback_query']['message']['message_id']
                ]);
            }
        }
    }
}
