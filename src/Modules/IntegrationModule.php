<?php
namespace App\Modules;

use App\Interfaces\PluginInterface;
use App\Core\Container;
use App\Telegram\Client;

class IntegrationModule implements PluginInterface {
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

        // Weather Command
        if (strpos($text, '/weather') === 0) {
            // Mock API call
            $city = trim(substr($text, 8)) ?: 'London';
            $client->sendMessage($chatId, "🌤 <b>Weather in $city</b>\n\nTemp: 22°C\nStatus: Sunny\nHumidity: 45%");
        }
        
        // Crypto Command
        if (strpos($text, '/price') === 0) {
            $coin = strtoupper(trim(substr($text, 7))) ?: 'BTC';
            $price = rand(10000, 60000);
            $client->sendMessage($chatId, "💰 <b>$coin Price:</b> $$price USD");
        }
    }
}
