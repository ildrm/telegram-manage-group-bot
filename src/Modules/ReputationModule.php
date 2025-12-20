<?php
namespace App\Modules;

use App\Interfaces\PluginInterface;
use App\Core\Container;
use App\Database\Database;
use App\Telegram\Client;

class ReputationModule implements PluginInterface {
    public function register(Container $container): void {
    }

    public function boot(Container $container): void {
    }

    public function getListeners(): array {
        return [
            'update.received' => 'handleUpdate'
        ];
    }

    private function parseCommand(string $text): array {
        $text = trim($text);
        if ($text === '') return ['', ''];

        $parts = preg_split('/\s+/', $text, 2);
        $cmd = $parts[0] ?? '';
        $args = $parts[1] ?? '';

        if (strpos($cmd, '@') !== false) {
            $cmd = explode('@', $cmd, 2)[0];
        }

        return [$cmd, $args];
    }

    public function handleUpdate(array $update, Container $container): void {
        if (!isset($update['message']['text'])) return;

        $message = $update['message'];
        $text = $message['text'];
        $chatId = $message['chat']['id'];

        [$cmd] = $this->parseCommand($text);
        
        // Check for thank you or + (must be a reply)
        if (preg_match('/^(\+|thx|thanks|thank you)/i', $text)) {
            if (!isset($message['reply_to_message'])) return;

            $db = $container->get(Database::class);
            $client = $container->get(Client::class);
            
            $fromId = $message['from']['id'];
            $toUser = $message['reply_to_message']['from'];
            $toId = $toUser['id'];

            // Prevent self-rep
            if ($fromId === $toId) {
                $client->sendMessage($chatId, "⛔ You cannot give reputation to yourself.");
                return;
            }

            // Update reputation
            $newRep = $this->incrementReputation($toId, $db);
            
            $client->sendMessage($chatId, "📈 <b>Reputation Increased!</b>\n\n{$toUser['first_name']} now has $newRep reputation points.");
            return;
        }

        // Command: /myrep
        if ($cmd === '/myrep') {
            $db = $container->get(Database::class);
            $client = $container->get(Client::class);
            $rep = $this->getReputation($message['from']['id'], $db);
            $client->sendMessage($chatId, "Your reputation: <b>$rep</b>");
            return;
        }
    }

    private function incrementReputation(int $userId, Database $db): int {
        // Ensure user exists
        $db->insertIgnore('users', ['user_id' => $userId]);
        
        $db->exec("UPDATE users SET reputation = reputation + 1 WHERE user_id = $userId");
        return $this->getReputation($userId, $db);
    }

    private function getReputation(int $userId, Database $db): int {
        $stmt = $db->prepare("SELECT reputation FROM users WHERE user_id = ?");
        $stmt->bindValue(1, $userId);
        $stmt->execute();
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ? (int)$result['reputation'] : 0;
    }
}
