<?php
namespace App\Modules;

use App\Interfaces\PluginInterface;
use App\Core\Container;
use App\Database\Database;
use App\Telegram\Client;
use App\Services\SettingsService;

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
        $settingsService = $container->get(SettingsService::class);

        // 1. New Member -> Restrict & Send Captcha
        if (isset($update['message']['new_chat_members'])) {
            $message = $update['message'];
            $chatId = $message['chat']['id'];
            
            // Skip private chats
            if ($message['chat']['type'] === 'private') {
                return;
            }
            
            // Check if CAPTCHA is enabled
            if (!$settingsService->isEnabled($chatId, 'captcha_enabled')) {
                return;
            }
            
            foreach ($message['new_chat_members'] as $user) {
                if ($user['is_bot'] ?? false) {
                    continue; // Skip bots
                }

                $userId = $user['id'];
                
                // Restrict user
                $client->restrictChatMember($chatId, $userId, [
                    'can_send_messages' => false,
                    'can_send_media_messages' => false,
                    'can_send_polls' => false,
                    'can_send_other_messages' => false,
                    'can_add_web_page_previews' => false,
                    'can_change_info' => false,
                    'can_invite_users' => false,
                    'can_pin_messages' => false
                ]);

                // Generate and send CAPTCHA
                $this->sendCaptcha($chatId, $userId, $user, $client, $db, $settingsService, $message['message_id']);
            }
            return;
        }

        // 2. Callback -> Verify
        if (isset($update['callback_query'])) {
            $this->handleCaptchaCallback($update['callback_query'], $container);
        }
    }

    private function sendCaptcha(
        int $chatId, 
        int $userId, 
        array $user, 
        Client $client, 
        Database $db, 
        SettingsService $settings,
        ?int $welcomeMsgId = null
    ): void {
        $captchaType = $settings->get($chatId, 'captcha_type', 'button');
        $answer = 'verify';
        $messageId = null;

        if ($captchaType === 'math') {
            // Math CAPTCHA
            $a = rand(1, 10);
            $b = rand(1, 10);
            $answer = (string)($a + $b);
            
            $wrong1 = (string)($a + $b + rand(1, 5));
            $wrong2 = (string)max(1, $a + $b - rand(1, 5));
            
            $options = [$answer, $wrong1, $wrong2];
            shuffle($options);
            
            $buttons = [];
            foreach ($options as $opt) {
                $buttons[] = [
                    'text' => $opt,
                    'callback_data' => "captcha:verify:$chatId:$userId:" . ($opt === $answer ? 'correct' : 'wrong')
                ];
            }
            
            $keyboard = ['inline_keyboard' => [array_chunk($buttons, 2)[0] ?? []]];
            $text = "Welcome {$user['first_name']}! Please solve: <b>$a + $b = ?</b>";
        } else {
            // Button CAPTCHA (default)
            $keyboard = [
                'inline_keyboard' => [[
                    ['text' => '✅ I am human', 'callback_data' => "captcha:verify:$chatId:$userId:correct"]
                ]]
            ];
            $text = "Welcome {$user['first_name']}! Please verify you are human.";
        }

        $sent = $client->sendMessage($chatId, $text, $keyboard);
        
        if ($sent && isset($sent['result']['message_id'])) {
            $messageId = $sent['result']['message_id'];
        }

        // Store CAPTCHA session
        $stmt = $db->prepare("
            INSERT OR REPLACE INTO captcha_sessions 
            (group_id, user_id, message_id, answer, created_at) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$chatId, $userId, $messageId, $answer, time()]);
    }

    private function handleCaptchaCallback(array $callback, Container $container): void {
        $data = $callback['data'] ?? '';
        $fromId = $callback['from']['id'] ?? 0;
        $queryId = $callback['id'] ?? '';
        $message = $callback['message'] ?? [];
        $chatId = $message['chat']['id'] ?? 0;
        $messageId = $message['message_id'] ?? 0;

        if (strpos($data, 'captcha:verify:') !== 0) {
            return;
        }

        $parts = explode(':', $data);
        if (count($parts) < 5) {
            return;
        }

        $targetChatId = (int)$parts[2];
        $targetUserId = (int)$parts[3];
        $answer = $parts[4] ?? 'wrong';

        // Only the target user can click
        if ($fromId !== $targetUserId) {
            $client = $container->get(Client::class);
            $client->answerCallbackQuery($queryId, "This button is not for you!", true);
            return;
        }

        $client = $container->get(Client::class);
        $db = $container->get(Database::class);

        // Verify session
        $stmt = $db->prepare("
            SELECT answer, message_id FROM captcha_sessions 
            WHERE group_id = ? AND user_id = ?
        ");
        $stmt->execute([$targetChatId, $targetUserId]);
        $session = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$session) {
            $client->answerCallbackQuery($queryId, "Session expired. Please contact an admin.", true);
            return;
        }

        // Check answer
        $isCorrect = ($answer === 'correct' && $session['answer'] === 'verify') || 
                     ($answer === $session['answer']);

        if ($isCorrect) {
            // Unrestrict user
            $client->restrictChatMember($targetChatId, $targetUserId, [
                'can_send_messages' => true,
                'can_send_media_messages' => true,
                'can_send_polls' => true,
                'can_send_other_messages' => true,
                'can_add_web_page_previews' => true,
                'can_change_info' => false,
                'can_invite_users' => true,
                'can_pin_messages' => false
            ]);

            // Delete CAPTCHA message
            if ($session['message_id']) {
                $client->deleteMessage($targetChatId, $session['message_id']);
            }

            // Clean up session
            $stmt = $db->prepare("DELETE FROM captcha_sessions WHERE group_id = ? AND user_id = ?");
            $stmt->execute([$targetChatId, $targetUserId]);

            $client->answerCallbackQuery($queryId, "✅ Verified! Welcome to the group.");
        } else {
            // Wrong answer - kick user
            $client->banChatMember($targetChatId, $targetUserId);
            if ($session['message_id']) {
                $client->deleteMessage($targetChatId, $session['message_id']);
            }
            $stmt = $db->prepare("DELETE FROM captcha_sessions WHERE group_id = ? AND user_id = ?");
            $stmt->execute([$targetChatId, $targetUserId]);
            $client->answerCallbackQuery($queryId, "❌ Wrong answer. You have been removed.", true);
        }
    }
}
