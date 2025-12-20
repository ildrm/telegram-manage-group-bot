<?php
namespace App\Modules;

use App\Interfaces\PluginInterface;
use App\Core\Container;
use App\Database\Database;
use App\Telegram\Client;
use App\Services\SettingsService;
use App\Services\AuthorizationService;

class ModerationModule implements PluginInterface {
    public function register(Container $container): void {
    }

    public function boot(Container $container): void {
    }

    public function getListeners(): array {
        return [
            'update.received' => 'handleMessage'
        ];
    }

    public function handleMessage(array $update, Container $container): void {
        if (!isset($update['message'])) {
            return;
        }

        $message = $update['message'];
        $chatId = $message['chat']['id'] ?? 0;
        $userId = $message['from']['id'] ?? 0;
        $text = $message['text'] ?? '';
        $messageId = $message['message_id'] ?? 0;
        $chatType = $message['chat']['type'] ?? '';

        // Skip private chats
        if ($chatType === 'private') {
            return;
        }

        // Skip if user is admin/owner
        $auth = $container->get(AuthorizationService::class);
        if ($auth->canManage($userId, $chatId)) {
            return;
        }

        // Skip whitelisted users
        $db = $container->get(Database::class);
        $stmt = $db->prepare("SELECT 1 FROM whitelist WHERE group_id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$chatId, $userId]);
        if ($stmt->fetch()) {
            return;
        }

        $client = $container->get(Client::class);
        $settings = $container->get(SettingsService::class);
        
        // 1. Anti-Flood
        if ($settings->isEnabled($chatId, 'antiflood_enabled')) {
            if ($this->checkFlood($userId, $chatId, $db, $settings)) {
                $client->deleteMessage($chatId, $messageId);
                $client->sendMessage($chatId, "⚠️ Flood detected. Message removed.");
                $this->restrictUser($client, $chatId, $userId, 300); // 5 minutes
                return;
            }
        }

        // 2. Anti-Link
        if ($settings->isEnabled($chatId, 'antilink_enabled')) {
            if (preg_match('/(https?:\/\/|t\.me\/|@\w+)/i', $text)) {
                $client->deleteMessage($chatId, $messageId);
                $this->addWarn($chatId, $userId, 0, 'Posted link', $container);
                return;
            }
        }

        // 3. Anti-Media
        if ($settings->isEnabled($chatId, 'antimedia_enabled')) {
            if (isset($message['photo']) || isset($message['video']) || 
                isset($message['document']) || isset($message['audio']) || 
                isset($message['voice']) || isset($message['video_note'])) {
                $client->deleteMessage($chatId, $messageId);
                $this->addWarn($chatId, $userId, 0, 'Posted media', $container);
                return;
            }
        }

        // 4. Blacklist words
        $blacklistWords = $settings->get($chatId, 'blacklist_words');
        if ($blacklistWords) {
            $words = json_decode($blacklistWords, true) ?: [];
            foreach ($words as $word) {
                if (stripos($text, $word) !== false) {
                    $client->deleteMessage($chatId, $messageId);
                    $this->addWarn($chatId, $userId, 0, 'Used blacklisted word', $container);
                    return;
                }
            }
        }

        // 5. Night Mode
        if ($settings->isEnabled($chatId, 'night_mode_enabled')) {
            if ($this->isNightMode($settings, $chatId)) {
                $client->deleteMessage($chatId, $messageId);
                return;
            }
        }

        // 6. Risk Scoring
        $riskScore = $this->calculateRiskScore($text);
        if ($riskScore > 80) {
            $client->deleteMessage($chatId, $messageId);
            $this->addWarn($chatId, $userId, 0, 'Potential spam detected', $container);
        }
    }

    private function checkFlood(int $userId, int $groupId, Database $db, SettingsService $settings): bool {
        $limit = (int)$settings->get($groupId, 'antiflood_limit', 5);
        $window = 10; // 10 seconds
        $now = time();

        // Clean old entries
        $db->exec("DELETE FROM rate_limits WHERE timestamp < " . ($now - $window));

        // Count recent messages
        $stmt = $db->prepare("
            SELECT COUNT(*) as cnt FROM rate_limits 
            WHERE user_id = ? AND timestamp > ?
        ");
        $stmt->execute([$userId, $now - $window]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (($result['cnt'] ?? 0) >= $limit) {
            return true;
        }

        // Log this message
        $stmt = $db->prepare("INSERT INTO rate_limits (user_id, timestamp) VALUES (?, ?)");
        $stmt->execute([$userId, $now]);

        return false;
    }

    private function restrictUser(Client $client, int $chatId, int $userId, int $seconds): void {
        $untilDate = time() + $seconds;
        $client->restrictChatMember($chatId, $userId, [
            'can_send_messages' => false
        ], $untilDate);
    }

    private function isNightMode(SettingsService $settings, int $groupId): bool {
        $start = $settings->get($groupId, 'night_mode_start', '22:00');
        $end = $settings->get($groupId, 'night_mode_end', '08:00');
        $now = date('H:i');

        if ($start > $end) {
            // Overnight mode (e.g., 22:00 to 08:00)
            return ($now >= $start || $now < $end);
        } else {
            // Daytime mode (e.g., 10:00 to 18:00)
            return ($now >= $start && $now < $end);
        }
    }

    private function calculateRiskScore(string $text): int {
        $score = 0;
        $len = strlen($text);
        if ($len == 0) {
            return 0;
        }

        // CAPS LOCK
        $upper = preg_match_all('/[A-Z]/', $text);
        if ($len > 10 && ($upper / $len) > 0.7) {
            $score += 30;
        }

        // Repetitive characters
        if (preg_match('/(.)\1{4,}/', $text)) {
            $score += 20;
        }

        // Excessive punctuation
        $punctCount = preg_match_all('/[!?.]{2,}/', $text);
        if ($punctCount > 2) {
            $score += 15;
        }

        // URL patterns
        if (preg_match('/(www\.|http|\.com|\.net|\.org)/i', $text)) {
            $score += 25;
        }

        return min($score, 100);
    }

    private function addWarn(int $groupId, int $userId, int $warnedBy, string $reason, Container $container): void {
        $db = $container->get(Database::class);
        $client = $container->get(Client::class);
        $settings = $container->get(SettingsService::class);

        // Add warn
        $stmt = $db->prepare("
            INSERT INTO warns (group_id, user_id, warned_by, reason, warned_at) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$groupId, $userId, $warnedBy, $reason, time()]);

        // Count warns
        $stmt = $db->prepare("
            SELECT COUNT(*) as cnt FROM warns 
            WHERE group_id = ? AND user_id = ?
        ");
        $stmt->execute([$groupId, $userId]);
        $warnCount = (int)($stmt->fetch(\PDO::FETCH_ASSOC)['cnt'] ?? 0);

        $maxWarns = (int)$settings->get($groupId, 'max_warns', 3);
        if ($warnCount >= $maxWarns) {
            // Execute action
            $warnAction = $settings->get($groupId, 'warn_action', 'mute');
            if ($warnAction === 'ban') {
                $client->banChatMember($groupId, $userId);
                $client->sendMessage($groupId, "🚫 User reached maximum warnings and has been banned.");
            } elseif ($warnAction === 'kick') {
                $client->kickChatMember($groupId, $userId);
                $client->sendMessage($groupId, "👢 User reached maximum warnings and has been kicked.");
            } else {
                // mute (default)
                $this->restrictUser($client, $groupId, $userId, 3600); // 1 hour
                $client->sendMessage($groupId, "🔇 User reached maximum warnings and has been muted for 1 hour.");
            }

            // Clear warns
            $stmt = $db->prepare("DELETE FROM warns WHERE group_id = ? AND user_id = ?");
            $stmt->execute([$groupId, $userId]);
        } else {
            $remaining = $maxWarns - $warnCount;
            $client->sendMessage($groupId, "⚠️ Warning issued. {$remaining} warning(s) remaining before action.");
        }
    }
}
