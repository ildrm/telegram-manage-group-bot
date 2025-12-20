<?php
namespace App\Services;

use App\Database\Database;

class SettingsService {
    private Database $db;

    public function __construct(Database $db) {
        $this->db = $db;
    }

    /**
     * Get all settings for a group, creating defaults if not exists
     */
    public function getSettings(int $groupId): array {
        $stmt = $this->db->prepare("SELECT * FROM settings WHERE group_id = ?");
        $stmt->execute([$groupId]);
        $settings = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$settings) {
            return $this->createDefaultSettings($groupId);
        }

        // Convert numeric strings to integers where appropriate
        $booleanFields = [
            'welcome_enabled', 'goodbye_enabled', 'captcha_enabled',
            'antiflood_enabled', 'antilink_enabled', 'antimedia_enabled',
            'antibot_enabled', 'night_mode_enabled', 'antiraid_enabled',
            'clean_service', 'verification_required'
        ];

        foreach ($booleanFields as $field) {
            if (isset($settings[$field])) {
                $settings[$field] = (int)$settings[$field];
            }
        }

        return $settings;
    }

    /**
     * Get a specific setting value
     */
    public function get(int $groupId, string $key, $default = null) {
        $settings = $this->getSettings($groupId);
        return $settings[$key] ?? $default;
    }

    /**
     * Update a setting value
     */
    public function set(int $groupId, string $key, $value): void {
        // Ensure settings exist
        $this->getSettings($groupId);

        if (is_array($value)) {
            $value = json_encode($value);
        } elseif (is_bool($value)) {
            $value = $value ? 1 : 0;
        }

        $stmt = $this->db->prepare("UPDATE settings SET $key = ? WHERE group_id = ?");
        $stmt->execute([$value, $groupId]);
    }

    /**
     * Update multiple settings at once
     */
    public function update(int $groupId, array $settings): void {
        // Ensure settings exist
        $this->getSettings($groupId);

        $fields = [];
        $values = [];
        foreach ($settings as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            } elseif (is_bool($value)) {
                $value = $value ? 1 : 0;
            }
            $fields[] = "$key = ?";
            $values[] = $value;
        }
        $values[] = $groupId;

        $sql = "UPDATE settings SET " . implode(', ', $fields) . " WHERE group_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
    }

    /**
     * Check if a setting is enabled
     */
    public function isEnabled(int $groupId, string $setting): bool {
        return (bool)$this->get($groupId, $setting, 0);
    }

    /**
     * Create default settings for a group
     */
    private function createDefaultSettings(int $groupId): array {
        $defaults = [
            'group_id' => $groupId,
            'welcome_enabled' => 1,
            'welcome_message' => 'Welcome {name}! 👋',
            'welcome_media' => null,
            'welcome_buttons' => null,
            'goodbye_enabled' => 0,
            'goodbye_message' => 'Goodbye {name}!',
            'captcha_enabled' => 1,
            'captcha_type' => 'button',
            'antiflood_enabled' => 1,
            'antiflood_limit' => 5,
            'antilink_enabled' => 0,
            'antimedia_enabled' => 0,
            'antibot_enabled' => 1,
            'night_mode_enabled' => 0,
            'night_mode_start' => '22:00',
            'night_mode_end' => '08:00',
            'blacklist_words' => null,
            'max_warns' => 3,
            'warn_action' => 'mute',
            'rules_text' => 'Please be respectful and follow community guidelines.',
            'log_channel' => null,
            'slow_mode' => 0,
            'antiraid_enabled' => 0,
            'clean_service' => 1,
            'language' => 'en',
            'verification_required' => 0
        ];

        $fields = array_keys($defaults);
        $placeholders = str_repeat('?,', count($fields) - 1) . '?';
        $sql = "INSERT INTO settings (" . implode(', ', $fields) . ") VALUES ($placeholders)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_values($defaults));

        return $defaults;
    }
}
