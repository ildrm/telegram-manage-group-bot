<?php
namespace App\Modules;

use App\Interfaces\PluginInterface;
use App\Core\Container;
use App\Database\Database;
use App\Telegram\Client;
use App\Services\SettingsService;
use App\Models\User;

class WelcomeModule implements PluginInterface {
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
        // Handle new members (welcome)
        if (isset($update['message']['new_chat_members'])) {
            $this->handleNewMembers($update['message'], $container);
        }

        // Handle left members (goodbye)
        if (isset($update['message']['left_chat_member'])) {
            $this->handleLeftMember($update['message'], $container);
        }
    }

    private function handleNewMembers(array $message, Container $container): void {
        $chatId = $message['chat']['id'] ?? 0;
        $chatType = $message['chat']['type'] ?? '';

        // Only handle groups
        if ($chatType !== 'supergroup' && $chatType !== 'group') {
            return;
        }

        $db = $container->get(Database::class);
        $client = $container->get(Client::class);
        $settings = $container->get(SettingsService::class);

        // Anti-Bot: auto-ban joining bot accounts (unless added by an admin).
        // Runs independently of the welcome message setting.
        if ($settings->isEnabled($chatId, 'antibot_enabled')) {
            $this->enforceAntiBot($message, $chatId, $client, $container);
        }

        // Check if welcome is enabled
        if (!$settings->isEnabled($chatId, 'welcome_enabled')) {
            return;
        }

        // Avoid a duplicate greeting: when CAPTCHA is on, CaptchaModule already
        // welcomes the user (and they're restricted until verified). The
        // configured welcome message would otherwise be shown twice and before
        // verification.
        if ($settings->isEnabled($chatId, 'captcha_enabled')) {
            return;
        }

        $welcomeMessage = $settings->get($chatId, 'welcome_message', 'Welcome {name}! 👋');
        $welcomeButtons = $settings->get($chatId, 'welcome_buttons');

        foreach ($message['new_chat_members'] as $memberData) {
            // Skip bots (they're handled by antibot module)
            if ($memberData['is_bot'] ?? false) {
                continue;
            }

            // Save user to database
            User::findOrCreate($db, $memberData);

            // Replace placeholders
            $name = $memberData['first_name'] ?? 'User';
            $username = $memberData['username'] ?? '';
            $groupTitle = $message['chat']['title'] ?? 'this group';

            $text = str_replace(
                ['{name}', '{username}', '{group}', '{mention}'],
                [
                    htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
                    $username ? '@' . $username : 'user',
                    htmlspecialchars($groupTitle, ENT_QUOTES, 'UTF-8'),
                    $username ? "<a href=\"tg://user?id={$memberData['id']}\">{$name}</a>" : $name
                ],
                $welcomeMessage
            );

            // Build keyboard if buttons are configured
            $keyboard = null;
            if ($welcomeButtons) {
                $buttons = json_decode($welcomeButtons, true);
                if ($buttons && is_array($buttons)) {
                    $keyboard = ['inline_keyboard' => $buttons];
                }
            }

            // Send welcome message
            try {
                $sent = $client->sendMessage($chatId, $text, $keyboard);
                
                // Auto-delete welcome message if clean_service is enabled
                if ($settings->isEnabled($chatId, 'clean_service') && $sent && isset($sent['result']['message_id'])) {
                    // Note: In production, you'd want to schedule this deletion via a job/cron
                    // For now, we'll just log it - actual deletion should happen after a delay
                    // This would be better handled by a background job
                }
            } catch (\Exception $e) {
                error_log("Failed to send welcome message: " . $e->getMessage());
            }
        }
    }

    /**
     * Ban any joining bot accounts unless they were added by a group admin/owner.
     */
    private function enforceAntiBot(array $message, int $chatId, Client $client, Container $container): void {
        $adderId = $message['from']['id'] ?? 0;
        $auth = $container->get(\App\Services\AuthorizationService::class);

        // Trust bots explicitly added by an admin/owner.
        $addedByAdmin = $adderId > 0 && $auth->canManage($adderId, $chatId);

        foreach ($message['new_chat_members'] as $memberData) {
            if (!($memberData['is_bot'] ?? false)) {
                continue;
            }
            if ($addedByAdmin) {
                continue;
            }
            $botId = $memberData['id'] ?? 0;
            if ($botId <= 0) {
                continue;
            }
            try {
                $client->banChatMember($chatId, $botId, null, true);
            } catch (\Exception $e) {
                error_log("Anti-bot ban failed for {$botId} in {$chatId}: " . $e->getMessage());
            }
        }
    }

    private function handleLeftMember(array $message, Container $container): void {
        $chatId = $message['chat']['id'] ?? 0;
        $chatType = $message['chat']['type'] ?? '';

        // Only handle groups
        if ($chatType !== 'supergroup' && $chatType !== 'group') {
            return;
        }

        $client = $container->get(Client::class);
        $settings = $container->get(SettingsService::class);

        // Check if goodbye is enabled
        if (!$settings->isEnabled($chatId, 'goodbye_enabled')) {
            return;
        }

        $member = $message['left_chat_member'] ?? [];
        if (empty($member)) {
            return;
        }

        $goodbyeMessage = $settings->get($chatId, 'goodbye_message', 'Goodbye {name}!');

        $name = $member['first_name'] ?? 'User';
        $username = $member['username'] ?? '';

        $text = str_replace(
            ['{name}', '{username}', '{mention}'],
            [
                htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
                $username ? '@' . $username : 'user',
                $username ? "<a href=\"tg://user?id={$member['id']}\">{$name}</a>" : $name
            ],
            $goodbyeMessage
        );

        try {
            $client->sendMessage($chatId, $text);
        } catch (\Exception $e) {
            error_log("Failed to send goodbye message: " . $e->getMessage());
        }
    }
}
