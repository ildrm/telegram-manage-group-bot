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
        $ch = curl_init($this->apiUrl . $this->token . "/" . $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return $response ? json_decode($response, true) : null;
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
}
