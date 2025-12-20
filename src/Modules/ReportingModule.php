<?php
namespace App\Modules;

use App\Interfaces\PluginInterface;
use App\Core\Container;
use App\Database\Database;
use App\Telegram\Client;
use App\Services\AuthorizationService;

class ReportingModule implements PluginInterface {
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
        // Handle report command or @admin mention
        if (!isset($update['message']['text'])) {
            return;
        }

        $message = $update['message'];
        $chatId = $message['chat']['id'] ?? 0;
        $chatType = $message['chat']['type'] ?? '';

        // Only handle group messages
        if ($chatType !== 'supergroup' && $chatType !== 'group') {
            return;
        }

        $text = $message['text'] ?? '';
        $cmdToken = preg_split('/\s+/', trim($text), 2)[0] ?? '';

        // Remove bot username if present
        if (strpos($cmdToken, '@') !== false) {
            $cmdToken = explode('@', $cmdToken, 2)[0];
        }

        // Check for /report command or @admin mention
        if ($cmdToken === '/report' || stripos($text, '@admin') !== false) {
            $this->handleReport($message, $container);
        }
    }

    private function handleReport(array $message, Container $container): void {
        $groupId = $message['chat']['id'] ?? 0;
        $userId = $message['from']['id'] ?? 0;
        $reporterName = $this->getUserName($message['from'] ?? []);
        $text = $message['text'] ?? '';
        $replyToMessage = $message['reply_to_message'] ?? null;

        $db = $container->get(Database::class);
        $client = $container->get(Client::class);
        $auth = $container->get(AuthorizationService::class);

        // Get all owners/admins of the group
        $owners = $auth->getOwners($groupId);

        if (empty($owners)) {
            // If no owners in DB, try to get Telegram admins
            $admins = $client->getChatAdministrators($groupId);
            if ($admins && isset($admins['result'])) {
                foreach ($admins['result'] as $admin) {
                    $owners[] = ['user_id' => $admin['user']['id']];
                }
            }
        }

        if (empty($owners)) {
            $client->sendMessage($groupId, "⚠️ No administrators found to send the report to.");
            return;
        }

        // Build report message
        $reportText = "🚨 <b>Report from " . htmlspecialchars($reporterName, ENT_QUOTES, 'UTF-8') . "</b>\n\n";
        $reportText .= "<b>Group:</b> " . htmlspecialchars($message['chat']['title'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') . "\n";
        $reportText .= "<b>Time:</b> " . date('Y-m-d H:i:s') . "\n\n";

        if ($replyToMessage) {
            // Report about a specific message
            $reportedUserId = $replyToMessage['from']['id'] ?? 0;
            $reportedUserName = $this->getUserName($replyToMessage['from'] ?? []);
            
            $reportText .= "<b>Reported User:</b> " . htmlspecialchars($reportedUserName, ENT_QUOTES, 'UTF-8') . " (ID: $reportedUserId)\n\n";
            
            $reportedText = $replyToMessage['text'] ?? '';
            if (empty($reportedText)) {
                $reportedText = '[Media Message]';
                if (isset($replyToMessage['caption'])) {
                    $reportedText = $replyToMessage['caption'];
                }
            }
            $reportText .= "<b>Reported Message:</b>\n" . htmlspecialchars($reportedText, ENT_QUOTES, 'UTF-8') . "\n\n";
            
            // Extract reason from report command if provided
            if (strpos($text, '/report') === 0) {
                $parts = preg_split('/\s+/', $text, 2);
                if (isset($parts[1]) && !empty($parts[1])) {
                    $reportText .= "<b>Reason:</b> " . htmlspecialchars($parts[1], ENT_QUOTES, 'UTF-8') . "\n\n";
                }
            }
            
            $reportText .= "<i>Reply to this message in the group to take action.</i>";
        } else {
            // General report
            $reportParts = preg_split('/\s+/', $text, 2);
            if (isset($reportParts[1])) {
                $reportText .= "<b>Report:</b>\n" . htmlspecialchars($reportParts[1], ENT_QUOTES, 'UTF-8');
            } else {
                $reportText .= "<b>Report:</b>\n" . htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            }
        }

        // Send report to all owners/admins
        $sentCount = 0;
        foreach ($owners as $owner) {
            $ownerId = $owner['user_id'] ?? 0;
            if ($ownerId > 0) {
                try {
                    $result = $client->sendMessage($ownerId, $reportText);
                    if ($result && ($result['ok'] ?? false)) {
                        $sentCount++;
                    }
                } catch (\Exception $e) {
                    error_log("Failed to send report to owner $ownerId: " . $e->getMessage());
                }
            }
        }

        if ($sentCount > 0) {
            $client->sendMessage($groupId, "✅ Report sent to {$sentCount} administrator(s).");
            
            // Log the report
            $stmt = $db->prepare("
                INSERT INTO audit_logs (group_id, user_id, action, details, created_at) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $details = json_encode([
                'type' => 'report',
                'reported_message_id' => $replyToMessage['message_id'] ?? null,
                'reported_user_id' => $replyToMessage['from']['id'] ?? null,
                'reason' => $text
            ]);
            $stmt->execute([$groupId, $userId, 'user_report', $details, time()]);
        } else {
            $client->sendMessage($groupId, "⚠️ Failed to send report. Please contact administrators directly.");
        }
    }

    private function getUserName(array $user): string {
        $firstName = $user['first_name'] ?? '';
        $lastName = $user['last_name'] ?? '';
        $username = $user['username'] ?? '';
        
        if ($firstName) {
            $name = $firstName . ($lastName ? ' ' . $lastName : '');
            return $username ? "$name (@$username)" : $name;
        }
        
        return $username ? "@$username" : "User #{$user['id']}";
    }
}
