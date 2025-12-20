<?php
namespace App\Telegram;

use App\Core\Config;

class Client {
    private string $token;
    private string $apiUrl = 'https://api.telegram.org/bot';

    public function __construct(Config $config) {
        $this->token = $config->get('BOT_TOKEN');
        if (empty($this->token) || $this->token === 'YOUR_BOT_TOKEN_HERE') {
            // Check for legacy constant if not in env
            if (defined('BOT_TOKEN')) {
                $this->token = BOT_TOKEN;
            }
        }
    }

    public function request(string $method, array $params = []): ?array {
        if (empty($this->token)) {
            throw new \RuntimeException("Bot token is not set");
        }

        $ch = curl_init($this->apiUrl . $this->token . "/" . $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            error_log("Telegram API request failed: $error");
            return null;
        }
        
        $data = json_decode($response, true);
        
        if ($data === null || !isset($data['ok'])) {
            error_log("Invalid Telegram API response: $response");
            return null;
        }
        
        if (!$data['ok']) {
            error_log("Telegram API error: " . ($data['description'] ?? 'Unknown error'));
            return $data;
        }
        
        return $data;
    }

    public function sendMessage(int $chatId, string $text, ?array $keyboard = null, string $parseMode = 'HTML'): ?array {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode
        ];
        
        if ($keyboard) {
            $params['reply_markup'] = $keyboard;
        }
        
        return $this->request('sendMessage', $params);
    }
    public function editMessageText(int $chatId, int $messageId, string $text, ?array $keyboard = null, string $parseMode = 'HTML'): ?array {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => $parseMode
        ];
        
        if ($keyboard) {
            $params['reply_markup'] = $keyboard;
        }
        
        return $this->request('editMessageText', $params);
    }

    public function deleteMessage(int $chatId, int $messageId): ?array {
        return $this->request('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId
        ]);
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false): ?array {
        $params = ['callback_query_id' => $callbackQueryId];
        if ($text) $params['text'] = $text;
        if ($showAlert) $params['show_alert'] = true;
        return $this->request('answerCallbackQuery', $params);
    }

    public function restrictChatMember(int $chatId, int $userId, array $permissions, ?int $untilDate = null): ?array {
        $params = [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'permissions' => $permissions
        ];
        if ($untilDate) $params['until_date'] = $untilDate;
        return $this->request('restrictChatMember', $params);
    }

    public function banChatMember(int $chatId, int $userId, ?int $untilDate = null, bool $revokeMessages = false): ?array {
        $params = [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'revoke_messages' => $revokeMessages
        ];
        if ($untilDate) $params['until_date'] = $untilDate;
        return $this->request('banChatMember', $params);
    }

    public function unbanChatMember(int $chatId, int $userId, bool $onlyIfBanned = true): ?array {
        return $this->request('unbanChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'only_if_banned' => $onlyIfBanned
        ]);
    }

    public function kickChatMember(int $chatId, int $userId): ?array {
        return $this->banChatMember($chatId, $userId, time() + 60); // Ban for 60 seconds = kick
    }

    public function getChatMember(int $chatId, int $userId): ?array {
        return $this->request('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
    }

    public function getChatAdministrators(int $chatId): ?array {
        return $this->request('getChatAdministrators', [
            'chat_id' => $chatId
        ]);
    }

    public function getChatMembersCount(int $chatId): ?array {
        return $this->request('getChatMembersCount', [
            'chat_id' => $chatId
        ]);
    }

    public function getChat(int $chatId): ?array {
        return $this->request('getChat', [
            'chat_id' => $chatId
        ]);
    }

    public function pinChatMessage(int $chatId, int $messageId, bool $disableNotification = false): ?array {
        return $this->request('pinChatMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'disable_notification' => $disableNotification
        ]);
    }

    public function unpinChatMessage(int $chatId, ?int $messageId = null): ?array {
        $params = ['chat_id' => $chatId];
        if ($messageId) $params['message_id'] = $messageId;
        return $this->request('unpinChatMessage', $params);
    }

    public function unpinAllChatMessages(int $chatId): ?array {
        return $this->request('unpinAllChatMessages', [
            'chat_id' => $chatId
        ]);
    }

    public function getMe(): ?array {
        return $this->request('getMe', []);
    }

    public function promoteChatMember(int $chatId, int $userId, array $permissions): ?array {
        $params = array_merge(['chat_id' => $chatId, 'user_id' => $userId], $permissions);
        return $this->request('promoteChatMember', $params);
    }

    public function setChatPermissions(int $chatId, array $permissions): ?array {
        return $this->request('setChatPermissions', [
            'chat_id' => $chatId,
            'permissions' => $permissions
        ]);
    }

    public function exportChatInviteLink(int $chatId): ?array {
        return $this->request('exportChatInviteLink', [
            'chat_id' => $chatId
        ]);
    }
}
