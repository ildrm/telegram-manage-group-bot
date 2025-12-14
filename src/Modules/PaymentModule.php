<?php
namespace App\Modules;

use App\Interfaces\PluginInterface;
use App\Core\Container;
use App\Telegram\Client;

class PaymentModule implements PluginInterface {
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
        if (!isset($update['message']['text'])) return;
        $text = $update['message']['text'];
        $chatId = $update['message']['chat']['id'];
        $client = $container->get(Client::class);

        if ($text === '/donate' || $text === '/subscribe') {
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '💎 1 Month ($5)', 'callback_data' => 'pay:1m']],
                    [['text' => '💎 1 Year ($50)', 'callback_data' => 'pay:1y']]
                ]
            ];
            
            $client->sendMessage($chatId, "<b>👑 Premium Subscription</b>\n\nUnlock exclusive features:", $keyboard);
        }
    }
}
