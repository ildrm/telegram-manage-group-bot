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

    public function handleUpdate(array $update, Container $container): void {
        if (!isset($update['message']['text'])) return;

        $message = $update['message'];
        $text = $message['text'];
        $chatId = $message['chat']['id'];
        
        // Check for thank you or +
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
        }
        
        // Command: /myrep
        if ($text === '/myrep') {
            $db = $container->get(Database::class);
            $client = $container->get(Client::class);
            $rep = $this->getReputation($message['from']['id'], $db);
            $client->sendMessage($chatId, "Your reputation: <b>$rep</b>");
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
        $result = $stmt->execute()->fetchArray();
        return $result ? (int)$result['reputation'] : 0;
    }
}
