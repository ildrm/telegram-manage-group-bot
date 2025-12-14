<?php
namespace App\Database;

class Schema {
    private Database $db;

    public function __construct(Database $db) {
        $this->db = $db;
    }

    public function init(): void {
        $driver = $this->db->getDriver();
        $isSqlite = $driver === 'sqlite';
        
        // Define types based on driver
        $pk = $isSqlite ? "INTEGER PRIMARY KEY AUTOINCREMENT" : "INT AUTO_INCREMENT PRIMARY KEY";
        $int = $isSqlite ? "INTEGER" : "INT";
        $text = "TEXT";
        
        // Users table
        $this->createTable('users', "
            user_id $int PRIMARY KEY, -- Telegram ID is explicit, no auto-increment
            username $text,
            first_name $text,
            last_name $text,
            reputation $int DEFAULT 0,
            trust_level $int DEFAULT 0,
            created_at $int DEFAULT 0
        ");
        
        // Groups table
        $this->createTable('groups', "
            group_id $int PRIMARY KEY,
            title $text,
            username $text,
            added_at $int DEFAULT 0,
            active $int DEFAULT 1
        ");
        
        // Group owners
        $this->createTable('group_owners', "
            id $pk,
            group_id $int,
            user_id $int,
            role $text DEFAULT 'owner',
            added_at $int DEFAULT 0,
            UNIQUE(group_id, user_id)
        ");
        
        // Settings table
        $this->createTable('settings', "
            group_id $int PRIMARY KEY,
            welcome_enabled $int DEFAULT 1,
            welcome_message $text,
            welcome_media $text,
            welcome_buttons $text,
            goodbye_enabled $int DEFAULT 0,
            goodbye_message $text,
            captcha_enabled $int DEFAULT 1,
            captcha_type $text DEFAULT 'button',
            antiflood_enabled $int DEFAULT 1,
            antiflood_limit $int DEFAULT 5,
            antilink_enabled $int DEFAULT 0,
            antimedia_enabled $int DEFAULT 0,
            antibot_enabled $int DEFAULT 1,
            night_mode_enabled $int DEFAULT 0,
            night_mode_start $text DEFAULT '22:00',
            night_mode_end $text DEFAULT '08:00',
            blacklist_words $text,
            max_warns $int DEFAULT 3,
            warn_action $text DEFAULT 'mute',
            rules_text $text,
            log_channel $text,
            slow_mode $int DEFAULT 0,
            antiraid_enabled $int DEFAULT 0,
            clean_service $int DEFAULT 1,
            language $text DEFAULT 'en',
            verification_required $int DEFAULT 0
        ");
        
        // Bans table
        $this->createTable('bans', "
            id $pk,
            group_id $int,
            user_id $int,
            banned_by $int,
            reason $text,
            banned_at $int DEFAULT 0,
            expires_at $int,
            UNIQUE(group_id, user_id)
        ");
        
        // Warns table
        $this->createTable('warns', "
            id $pk,
            group_id $int,
            user_id $int,
            warned_by $int,
            reason $text,
            warned_at $int DEFAULT 0
        ");
        
        // Module settings
        $this->createTable('module_settings', "
            group_id $int,
            module $text,
            setting_key $text,
            setting_value $text,
            UNIQUE(group_id, module, setting_key)
        ");
        
        // Audit Logs
        $this->createTable('audit_logs', "
            id $pk,
            group_id $int,
            user_id $int,
            action $text,
            details $text,
            created_at $int DEFAULT 0
        ");

        // Rate Limits (Memory table for MySQL ideally, but standard for compatibility)
        $this->createTable('rate_limits', "
            user_id $int,
            timestamp $int
        ");
        
        // Indexes
        $this->createIndex('idx_group_owners', 'group_owners', 'user_id, group_id');
        $this->createIndex('idx_audit_logs', 'audit_logs', 'group_id, created_at');
    }

    private function createTable(string $name, string $columns): void {
        $sql = "CREATE TABLE IF NOT EXISTS $name ($columns)";
        // MySQL requires ENGINE definition usually, but default InnoDB is fine.
        // Also syntax check: 'active INTEGER DEFAULT 1' works in MySQL.
        $this->db->exec($sql);
    }
    
    private function createIndex(string $name, string $table, string $columns): void {
        // SQLite: CREATE INDEX IF NOT EXISTS
        // MySQL: CREATE INDEX ... (no IF NOT EXISTS in old versions, but 8.0 support it? No, standard MySQL doesn't have IF NOT EXISTS for indexes easily)
        // Helper to check existence
        
        if ($this->db->getDriver() === 'sqlite') {
            $this->db->exec("CREATE INDEX IF NOT EXISTS $name ON $table($columns)");
        } else {
            // MySQL approach: check information_schema or just try/catch
            try {
                $this->db->exec("CREATE INDEX $name ON $table($columns)");
            } catch (\Exception $e) {
                // Ignore "Duplicate key name" error
            }
        }
    }
}
