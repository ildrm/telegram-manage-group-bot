<?php
namespace App\Modules;

use App\Interfaces\PluginInterface;
use App\Core\Container;
use App\Database\Database;

class AnalyticsModule implements PluginInterface {
    public function register(Container $container): void {
    }

    public function boot(Container $container): void {
    }

    public function getListeners(): array {
        return [
            'update.received' => 'logEvent'
        ];
    }

    public function logEvent(array $update, Container $container): void {
        $db = $container->get(Database::class);
        $today = date('Y-m-d');
        
        // Log generic activity
        if (isset($update['message'])) {
            $chatId = $update['message']['chat']['id'];
            // Only log group activity
            if ($update['message']['chat']['type'] === 'private') return;
            
            $db->upsert('stats', [
                'group_id' => $chatId,
                'event_type' => 'message',
                'event_date' => $today,
                'count' => 1
            ], ['count'], 'group_id, event_type, event_date'); // Unique key hint for SQLite fallback
        }
        
        // Log Joins
        if (isset($update['message']['new_chat_members'])) {
             $chatId = $update['message']['chat']['id'];
             $count = count($update['message']['new_chat_members']);
             
             $db->upsert('stats', [
                'group_id' => $chatId,
                'event_type' => 'join',
                'event_date' => $today,
                'count' => $count
             ], ['count'], 'group_id, event_type, event_date');
        }
    }
}
