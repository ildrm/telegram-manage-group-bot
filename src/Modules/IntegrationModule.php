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

    private function fetchJson(string $url, Container $container = null): array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'TelegramBot/1.0');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        // Proxy Support via Config
        $proxy = null;
        if ($container) {
             try {
                $config = $container->get(\App\Core\Config::class);
                $proxy = $config->get('HTTP_PROXY');
             } catch (\Throwable $e) {}
        }
        
        if ($proxy) {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
             file_put_contents(__DIR__ . '/../../storage/logs/error.log', 
                date('[Y-m-d H:i:s] ') . "API Error [$url] (Proxy: " . ($proxy ?: 'None') . "): ($httpCode) $error\n", 
                FILE_APPEND
            );
        }

        return json_decode($response, true) ?? [];
    }

    // Pass container to fetchJson helper
    private function handleWeather(int $chatId, string $city, Client $client, Container $container): void {
        // 1. Geocoding
        $geoUrl = "https://geocoding-api.open-meteo.com/v1/search?name=" . urlencode($city) . "&count=1&language=en&format=json";
        $geoData = $this->fetchJson($geoUrl, $container);
        
        // Fallback to wttr.in if empty/fail (simple regex parse or just text)
        // For now keep it simple, just fix the proxy.
        // ...
        
        if (empty($geoData['results'])) {
             // Try fallback? No, let's trust the fix first.
            $client->sendMessage($chatId, "❌ City not found or API unavailable.");
            return;
        }

        $lat = $geoData['results'][0]['latitude'];
        $lon = $geoData['results'][0]['longitude'];
        $cityName = $geoData['results'][0]['name'];
        $country = $geoData['results'][0]['country'] ?? '';

        // 2. Weather Data
        $weatherUrl = "https://api.open-meteo.com/v1/forecast?latitude=$lat&longitude=$lon&current_weather=true";
        $weatherData = $this->fetchJson($weatherUrl, $container);

        if (!isset($weatherData['current_weather'])) {
            $client->sendMessage($chatId, "❌ Could not fetch weather data.");
            return;
        }

        $current = $weatherData['current_weather'];
        $temp = $current['temperature'];
        $wind = $current['windspeed'];
        
        $msg = "🌤 <b>Weather in $cityName, $country</b>\n\n";
        $msg .= "🌡 <b>Temperature:</b> {$temp}°C\n";
        $msg .= "💨 <b>Wind Speed:</b> {$wind} km/h\n";
        
        $client->sendMessage($chatId, $msg);
    }
    
    private function showCryptoMenu(int $chatId, Client $client): void {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => 'Bitcoin (BTC)', 'callback_data' => 'price:bitcoin'],
                    ['text' => 'Ethereum (ETH)', 'callback_data' => 'price:ethereum']
                ],
                [
                    ['text' => 'Toncoin (TON)', 'callback_data' => 'price:the-open-network'],
                    ['text' => 'Tether (USDT)', 'callback_data' => 'price:tether']
                ],
                [
                    ['text' => 'Solana (SOL)', 'callback_data' => 'price:solana']
                ]
            ]
        ];

        $client->sendMessage($chatId, "💰 <b>Select a cryptocurrency:</b>", $keyboard);
    }

    private function parseCommand(string $text): array {
        $text = trim($text);
        if ($text === '') return ['', ''];

        $parts = preg_split('/\s+/', $text, 2);
        $cmd = $parts[0] ?? '';
        $args = $parts[1] ?? '';

        // Telegram may send commands as /cmd@BotUsername in groups
        if (strpos($cmd, '@') !== false) {
            $cmd = explode('@', $cmd, 2)[0];
        }

        return [$cmd, $args];
    }

    // Update call sites
    public function handleUpdate(array $update, Container $container): void {
        $client = $container->get(Client::class);

        // Handle Callback Queries (for Price)
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query'], $client, $container);
            return;
        }

        if (!isset($update['message']['text'])) return;
        $text = $update['message']['text'];
        $chatId = $update['message']['chat']['id'];

        [$cmd, $args] = $this->parseCommand($text);

        // Weather Command
        if ($cmd === '/weather') {
            // Allow users to paste URL-encoded strings like United%20Kingdom
            $city = trim(rawurldecode($args));
            if ($city === '') {
                $client->sendMessage($chatId, "⚠️ Please specify a city.\nExample: <code>/weather London</code>");
                return;
            }
            $this->handleWeather($chatId, $city, $client, $container);
        }

        // Crypto Command
        if ($cmd === '/price') {
            $this->showCryptoMenu($chatId, $client);
        }
    }
    
    private function handleCallback(array $callback, Client $client, Container $container): void {
        $data = $callback['data'];
        $chatId = $callback['message']['chat']['id'];
        $messageId = $callback['message']['message_id'];

        if (strpos($data, 'price:') === 0) {
            $coinId = substr($data, 6);
            $this->handlePrice($chatId, $messageId, $coinId, $client, $container);
        }
    }

    private function handlePrice(int $chatId, int $messageId, string $coinId, Client $client, Container $container): void {
        $url = "https://api.coingecko.com/api/v3/simple/price?ids=$coinId&vs_currencies=usd,eur";
        $data = $this->fetchJson($url, $container);
        
        if (empty($data[$coinId])) {
              if ($coinId !== 'menu') 
                  $client->editMessageText($chatId, $messageId, "❌ Price data unavailable.");
              else 
                  $this->showCryptoMenu($chatId, $client); // Create new message for menu if coming back
              return;
        }
        // ... (rest same)
        $prices = $data[$coinId];
        $usd = number_format($prices['usd'], 2);
        $eur = number_format($prices['eur'], 2);
        $coinName = ucfirst(str_replace('-', ' ', $coinId));

        $msg = "💰 <b>$coinName Price</b>\n\n";
        $msg .= "🇺🇸 <b>USD:</b> $$usd\n";
        $msg .= "🇪🇺 <b>EUR:</b> €$eur\n";
        $msg .= "\n<i>Updated: " . date('H:i:s') . "</i>";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Refresh', 'callback_data' => "price:$coinId"],
                    ['text' => '🔙 Menu', 'callback_data' => 'price:menu']
                ]
            ]
        ];
        
        $client->editMessageText($chatId, $messageId, $msg, $keyboard);
    }
}
