
-- Users table
CREATE TABLE IF NOT EXISTS users (
    user_id BIGINT PRIMARY KEY, -- Telegram IDs are large
    username TEXT,
    first_name TEXT,
    last_name TEXT,
    reputation INT DEFAULT 0,
    trust_level INT DEFAULT 0,
    created_at INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Groups table
CREATE TABLE IF NOT EXISTS `groups` (
    group_id BIGINT PRIMARY KEY,
    title TEXT,
    username TEXT,
    added_at INT DEFAULT 0,
    active TINYINT DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Group owners
CREATE TABLE IF NOT EXISTS group_owners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT,
    user_id BIGINT,
    role VARCHAR(50) DEFAULT 'owner',
    added_at INT DEFAULT 0,
    UNIQUE KEY unique_owner (group_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Settings table
CREATE TABLE IF NOT EXISTS settings (
    group_id BIGINT PRIMARY KEY,
    welcome_enabled TINYINT DEFAULT 1,
    welcome_message TEXT,
    welcome_media TEXT,
    welcome_buttons TEXT,
    goodbye_enabled TINYINT DEFAULT 0,
    goodbye_message TEXT,
    captcha_enabled TINYINT DEFAULT 1,
    captcha_type VARCHAR(20) DEFAULT 'button',
    antiflood_enabled TINYINT DEFAULT 1,
    antiflood_limit INT DEFAULT 5,
    antilink_enabled TINYINT DEFAULT 0,
    antimedia_enabled TINYINT DEFAULT 0,
    antibot_enabled TINYINT DEFAULT 1,
    night_mode_enabled TINYINT DEFAULT 0,
    night_mode_start VARCHAR(10),
    night_mode_end VARCHAR(10),
    blacklist_words TEXT,
    max_warns INT DEFAULT 3,
    warn_action VARCHAR(20) DEFAULT 'mute',
    rules_text TEXT,
    log_channel VARCHAR(100),
    slow_mode INT DEFAULT 0,
    antiraid_enabled TINYINT DEFAULT 0,
    clean_service TINYINT DEFAULT 1,
    language VARCHAR(10) DEFAULT 'en',
    verification_required TINYINT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bans table
CREATE TABLE IF NOT EXISTS bans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT,
    user_id BIGINT,
    banned_by BIGINT,
    reason TEXT,
    banned_at INT DEFAULT 0,
    expires_at INT,
    UNIQUE KEY unique_ban (group_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Warns table
CREATE TABLE IF NOT EXISTS warns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT,
    user_id BIGINT,
    warned_by BIGINT,
    reason TEXT,
    warned_at INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Module settings
CREATE TABLE IF NOT EXISTS module_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT,
    module VARCHAR(50),
    setting_key VARCHAR(100),
    setting_value TEXT,
    UNIQUE KEY unique_setting (group_id, module, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit Logs
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT,
    user_id BIGINT,
    action VARCHAR(100),
    details TEXT,
    created_at INT DEFAULT 0,
    INDEX idx_group_date (group_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate Limits
CREATE TABLE IF NOT EXISTS rate_limits (
    user_id BIGINT,
    timestamp INT,
    INDEX idx_user_time (user_id, timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Statistics
CREATE TABLE IF NOT EXISTS stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT,
    event_type VARCHAR(50),
    event_date DATE,
    count INT DEFAULT 1,
    UNIQUE KEY unique_stat (group_id, event_type, event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
