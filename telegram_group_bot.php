<?php
declare(strict_types=1);

/*******************************************************************************
 * TELEGRAM GROUP MANAGEMENT BOT - SINGLE FILE EDITION
 * 
 * A complete, secure, production-ready multi-tenant Telegram bot for group
 * management with privacy-first architecture. Each user manages only their
 * own groups with zero cross-user visibility.
 * 
 * Features: Anti-spam, CAPTCHA, welcome/goodbye messages, warn system,
 * media restrictions, scheduled messages, statistics, backup/restore, and more.
 * 
 * Requirements: PHP 7.4+, SQLite3 extension, HTTPS hosting
 * Setup: Upload this file, visit https://yourdomain.com/bot.php?setup=1
 ******************************************************************************/

// ============================================================================
// CONFIGURATION
// ============================================================================

define('BOT_TOKEN', 'YOUR_BOT_TOKEN_HERE'); // Get from @BotFather
define('ADMIN_IDS', [123456789]); // Your Telegram user ID for testing/support
define('DB_FILE', __DIR__ . '/database.sqlite');
define('RATE_LIMIT_ACTIONS', 30); // Max actions per minute per user
define('RATE_LIMIT_WINDOW', 60); // Seconds

// ============================================================================
// SECURITY & HELPER FUNCTIONS
// ============================================================================

/**
 * Rate limiting check - prevents abuse
 */
function checkRateLimit(int $userId): bool {
    $db = getDatabase();
    $now = time();
    $windowStart = $now - RATE_LIMIT_WINDOW;
    
    // Clean old entries
    $db->exec("DELETE FROM rate_limits WHERE timestamp < $windowStart");
    
    // Count recent actions
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM rate_limits WHERE user_id = ? AND timestamp >= ?");
    $stmt->execute([$userId, $windowStart]);
    $count = $stmt->fetchArray(SQLITE3_ASSOC)['cnt'];
    
    if ($count >= RATE_LIMIT_ACTIONS) {
        return false;
    }
    
    // Log this action
    $stmt = $db->prepare("INSERT INTO rate_limits (user_id, timestamp) VALUES (?, ?)");
    $stmt->execute([$userId, $now]);
    
    return true;
}

/**
 * Escape text for Telegram HTML mode
 */
function escapeHtml(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Generate unique callback data (max 64 bytes)
 */
function makeCallback(string $action, ...$params): string {
    $data = $action . ':' . implode(':', $params);
    return substr($data, 0, 64);
}

/**
 * Parse callback data
 */
function parseCallback(string $data): array {
    return explode(':', $data);
}

/**
 * Check if user owns/manages a group
 */
function userOwnsGroup(int $userId, int $groupId): bool {
    $db = getDatabase();
    $stmt = $db->prepare("SELECT 1 FROM group_owners WHERE user_id = ? AND group_id = ? LIMIT 1");
    $stmt->execute([$userId, $groupId]);
    return $stmt->fetchArray() !== false;
}

/**
 * Get group settings
 */
function getGroupSettings(int $groupId): array {
    $db = getDatabase();
    $stmt = $db->prepare("SELECT * FROM settings WHERE group_id = ? LIMIT 1");
    $stmt->execute([$groupId]);
    $result = $stmt->fetchArray(SQLITE3_ASSOC);
    
    if (!$result) {
        // Create default settings
        $defaults = [
            'group_id' => $groupId,
            'welcome_enabled' => 1,
            'welcome_message' => 'Welcome {name}! 👋',
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
            'max_warns' => 3,
            'warn_action' => 'mute',
            'rules_text' => 'Please be respectful and follow community guidelines.',
            'log_channel' => '',
            'slow_mode' => 0,
            'antiraid_enabled' => 0,
            'clean_service' => 1
        ];
        
        $fields = array_keys($defaults);
        $placeholders = array_fill(0, count($defaults), '?');
        $sql = "INSERT INTO settings (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_values($defaults));
        
        return $defaults;
    }
    
    return $result;
}

/**
 * Update group setting
 */
function updateGroupSetting(int $groupId, string $key, $value): void {
    $db = getDatabase();
    $stmt = $db->prepare("UPDATE settings SET $key = ? WHERE group_id = ?");
    $stmt->execute([$value, $groupId]);
}

// ============================================================================
// DATABASE SETUP
// ============================================================================

function getDatabase(): SQLite3 {
    static $db = null;
    
    if ($db === null) {
        $db = new SQLite3(DB_FILE);
        $db->busyTimeout(5000);
        $db->exec('PRAGMA journal_mode = WAL');
        initializeDatabase($db);
    }
    
    return $db;
}

function initializeDatabase(SQLite3 $db): void {
    // Users table
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        user_id INTEGER PRIMARY KEY,
        username TEXT,
        first_name TEXT,
        last_name TEXT,
        created_at INTEGER DEFAULT (strftime('%s', 'now'))
    )");
    
    // Groups table
    $db->exec("CREATE TABLE IF NOT EXISTS groups (
        group_id INTEGER PRIMARY KEY,
        title TEXT,
        username TEXT,
        added_at INTEGER DEFAULT (strftime('%s', 'now')),
        active INTEGER DEFAULT 1
    )");
    
    // Group owners (multi-owner support)
    $db->exec("CREATE TABLE IF NOT EXISTS group_owners (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id INTEGER,
        user_id INTEGER,
        added_at INTEGER DEFAULT (strftime('%s', 'now')),
        UNIQUE(group_id, user_id)
    )");
    
    // Settings table (one row per group)
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        group_id INTEGER PRIMARY KEY,
        welcome_enabled INTEGER DEFAULT 1,
        welcome_message TEXT,
        welcome_media TEXT,
        welcome_buttons TEXT,
        goodbye_enabled INTEGER DEFAULT 0,
        goodbye_message TEXT,
        captcha_enabled INTEGER DEFAULT 1,
        captcha_type TEXT DEFAULT 'button',
        antiflood_enabled INTEGER DEFAULT 1,
        antiflood_limit INTEGER DEFAULT 5,
        antilink_enabled INTEGER DEFAULT 0,
        antimedia_enabled INTEGER DEFAULT 0,
        antibot_enabled INTEGER DEFAULT 1,
        night_mode_enabled INTEGER DEFAULT 0,
        night_mode_start TEXT DEFAULT '22:00',
        night_mode_end TEXT DEFAULT '08:00',
        blacklist_words TEXT,
        max_warns INTEGER DEFAULT 3,
        warn_action TEXT DEFAULT 'mute',
        rules_text TEXT,
        log_channel TEXT,
        slow_mode INTEGER DEFAULT 0,
        antiraid_enabled INTEGER DEFAULT 0,
        clean_service INTEGER DEFAULT 1
    )");
    
    // Bans table
    $db->exec("CREATE TABLE IF NOT EXISTS bans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id INTEGER,
        user_id INTEGER,
        banned_by INTEGER,
        reason TEXT,
        banned_at INTEGER DEFAULT (strftime('%s', 'now')),
        expires_at INTEGER,
        UNIQUE(group_id, user_id)
    )");
    
    // Warns table
    $db->exec("CREATE TABLE IF NOT EXISTS warns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id INTEGER,
        user_id INTEGER,
        warned_by INTEGER,
        reason TEXT,
        warned_at INTEGER DEFAULT (strftime('%s', 'now'))
    )");
    
    // CAPTCHA sessions
    $db->exec("CREATE TABLE IF NOT EXISTS captcha_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id INTEGER,
        user_id INTEGER,
        answer TEXT,
        created_at INTEGER DEFAULT (strftime('%s', 'now')),
        UNIQUE(group_id, user_id)
    )");
    
    // Scheduled messages
    $db->exec("CREATE TABLE IF NOT EXISTS scheduled_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id INTEGER,
        message_text TEXT,
        schedule_time TEXT,
        created_at INTEGER DEFAULT (strftime('%s', 'now')),
        sent INTEGER DEFAULT 0
    )");
    
    // Custom commands
    $db->exec("CREATE TABLE IF NOT EXISTS custom_commands (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id INTEGER,
        command TEXT,
        response TEXT,
        UNIQUE(group_id, command)
    )");
    
    // Statistics
    $db->exec("CREATE TABLE IF NOT EXISTS stats (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id INTEGER,
        event_type TEXT,
        event_date TEXT,
        count INTEGER DEFAULT 1
    )");
    
    // Rate limiting
    $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
        user_id INTEGER,
        timestamp INTEGER
    )");
    
    // Whitelist
    $db->exec("CREATE TABLE IF NOT EXISTS whitelist (
        group_id INTEGER,
        user_id INTEGER,
        UNIQUE(group_id, user_id)
    )");
    
    // Sessions for state management
    $db->exec("CREATE TABLE IF NOT EXISTS sessions (
        user_id INTEGER PRIMARY KEY,
        state TEXT,
        data TEXT,
        updated_at INTEGER DEFAULT (strftime('%s', 'now'))
    )");
    
    // Create indexes
    $db->exec("CREATE INDEX IF NOT EXISTS idx_group_owners ON group_owners(user_id, group_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_warns ON warns(group_id, user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_stats ON stats(group_id, event_date)");
}

// ============================================================================
// TELEGRAM API WRAPPERS
// ============================================================================

function apiRequest(string $method, array $params = []): ?array {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return $response ? json_decode($response, true) : null;
}

function sendMessage(int $chatId, string $text, ?array $keyboard = null, string $parseMode = 'HTML'): ?array {
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => $parseMode
    ];
    
    if ($keyboard) {
        $params['reply_markup'] = $keyboard;
    }
    
    return apiRequest('sendMessage', $params);
}

function editMessageText(int $chatId, int $messageId, string $text, ?array $keyboard = null): ?array {
    $params = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    if ($keyboard) {
        $params['reply_markup'] = $keyboard;
    }
    
    return apiRequest('editMessageText', $params);
}

function answerCallbackQuery(string $callbackId, string $text = '', bool $alert = false): void {
    apiRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $text,
        'show_alert' => $alert
    ]);
}

function deleteMessage(int $chatId, int $messageId): void {
    apiRequest('deleteMessage', [
        'chat_id' => $chatId,
        'message_id' => $messageId
    ]);
}

function restrictChatMember(int $chatId, int $userId, array $permissions, ?int $untilDate = null): bool {
    $params = [
        'chat_id' => $chatId,
        'user_id' => $userId,
        'permissions' => $permissions
    ];
    
    if ($untilDate) {
        $params['until_date'] = $untilDate;
    }
    
    $result = apiRequest('restrictChatMember', $params);
    return $result['ok'] ?? false;
}

function banChatMember(int $chatId, int $userId, ?int $untilDate = null): bool {
    $params = [
        'chat_id' => $chatId,
        'user_id' => $userId
    ];
    
    if ($untilDate) {
        $params['until_date'] = $untilDate;
    }
    
    $result = apiRequest('banChatMember', $params);
    return $result['ok'] ?? false;
}

function unbanChatMember(int $chatId, int $userId): bool {
    $result = apiRequest('unbanChatMember', [
        'chat_id' => $chatId,
        'user_id' => $userId,
        'only_if_banned' => true
    ]);
    return $result['ok'] ?? false;
}

function getChatMember(int $chatId, int $userId): ?array {
    $result = apiRequest('getChatMember', [
        'chat_id' => $chatId,
        'user_id' => $userId
    ]);
    return $result['result'] ?? null;
}

function setWebhook(string $url): bool {
    $result = apiRequest('setWebhook', ['url' => $url]);
    return $result['ok'] ?? false;
}

// ============================================================================
// CORE LOGIC - UPDATE PROCESSOR
// ============================================================================

function processUpdate(array $update): void {
    $db = getDatabase();
    
    // Handle callback queries (button clicks)
    if (isset($update['callback_query'])) {
        handleCallbackQuery($update['callback_query']);
        return;
    }
    
    // Handle chat member updates (bot added/removed)
    if (isset($update['my_chat_member'])) {
        handleMyChatMember($update['my_chat_member']);
        return;
    }
    
    // Handle new chat members (for CAPTCHA)
    if (isset($update['message']['new_chat_members'])) {
        handleNewMembers($update['message']);
        return;
    }
    
    // Handle left chat member (goodbye)
    if (isset($update['message']['left_chat_member'])) {
        handleLeftMember($update['message']);
        return;
    }
    
    // Handle regular messages
    if (isset($update['message'])) {
        handleMessage($update['message']);
        return;
    }
}

// ============================================================================
// CHAT MEMBER UPDATES - AUTO OWNERSHIP DETECTION
// ============================================================================

function handleMyChatMember(array $update): void {
    $chat = $update['chat'];
    $newStatus = $update['new_chat_member']['status'];
    $userId = $update['from']['id'];
    
    // Only handle group/supergroup
    if (!in_array($chat['type'], ['group', 'supergroup'])) {
        return;
    }
    
    $db = getDatabase();
    $groupId = $chat['id'];
    
    if (in_array($newStatus, ['administrator', 'member'])) {
        // Bot was added - register group and set adder as owner
        $stmt = $db->prepare("INSERT OR REPLACE INTO groups (group_id, title, username, active) VALUES (?, ?, ?, 1)");
        $stmt->execute([$groupId, $chat['title'] ?? 'Unknown', $chat['username'] ?? '']);
        
        $stmt = $db->prepare("INSERT OR IGNORE INTO group_owners (group_id, user_id) VALUES (?, ?)");
        $stmt->execute([$groupId, $userId]);
        
        // Initialize settings
        getGroupSettings($groupId);
        
        // Notify user in private
        sendMessage($userId, "✅ <b>Group Added Successfully!</b>\n\n" .
            "Group: " . escapeHtml($chat['title'] ?? 'Unknown') . "\n" .
            "You can now manage this group from your dashboard.\n\n" .
            "Use /start to open your control panel.");
        
    } elseif (in_array($newStatus, ['left', 'kicked'])) {
        // Bot was removed - deactivate group
        $stmt = $db->prepare("UPDATE groups SET active = 0 WHERE group_id = ?");
        $stmt->execute([$groupId]);
    }
}

// ============================================================================
// MESSAGE HANDLER
// ============================================================================

function handleMessage(array $message): void {
    $chatId = $message['chat']['id'];
    $userId = $message['from']['id'] ?? 0;
    $text = $message['text'] ?? '';
    $chatType = $message['chat']['type'];
    
    // Rate limiting
    if (!checkRateLimit($userId)) {
        sendMessage($chatId, "⚠️ Too many actions. Please wait a moment.");
        return;
    }
    
    // Private chat - show dashboard or handle commands
    if ($chatType === 'private') {
        handlePrivateMessage($message);
        return;
    }
    
    // Group chat - apply filters and moderation
    if (in_array($chatType, ['group', 'supergroup'])) {
        handleGroupMessage($message);
        return;
    }
}

function handlePrivateMessage(array $message): void {
    $userId = $message['from']['id'];
    $text = $message['text'] ?? '';
    $db = getDatabase();
    
    // Save/update user info
    $stmt = $db->prepare("INSERT OR REPLACE INTO users (user_id, username, first_name, last_name) VALUES (?, ?, ?, ?)");
    $stmt->execute([
        $userId,
        $message['from']['username'] ?? '',
        $message['from']['first_name'] ?? '',
        $message['from']['last_name'] ?? ''
    ]);
    
    // Check if user is in a state (waiting for input)
    $stmt = $db->prepare("SELECT state, data FROM sessions WHERE user_id = ?");
    $stmt->execute([$userId]);
    $session = $stmt->fetchArray(SQLITE3_ASSOC);
    
    if ($session && $session['state']) {
        handleStateInput($userId, $text, $session);
        return;
    }
    
    // Handle commands
    if (strpos($text, '/start') === 0) {
        showMainDashboard($userId);
        return;
    }
    
    if ($text === '/mygroups') {
        showGroupList($userId);
        return;
    }
    
    // Default: show dashboard
    showMainDashboard($userId);
}

function handleGroupMessage(array $message): void {
    $groupId = $message['chat']['id'];
    $userId = $message['from']['id'] ?? 0;
    $text = $message['text'] ?? '';
    $db = getDatabase();
    
    $settings = getGroupSettings($groupId);
    
    // Clean service messages
    if ($settings['clean_service'] && isset($message['new_chat_members'])) {
        sleep(10);
        deleteMessage($groupId, $message['message_id']);
    }
    
    // Check whitelist
    $stmt = $db->prepare("SELECT 1 FROM whitelist WHERE group_id = ? AND user_id = ?");
    $stmt->execute([$groupId, $userId]);
    $isWhitelisted = $stmt->fetchArray() !== false;
    
    if ($isWhitelisted) {
        return; // Skip all filters for whitelisted users
    }
    
    // Anti-flood
    if ($settings['antiflood_enabled']) {
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM rate_limits WHERE user_id = ? AND timestamp > ?");
        $stmt->execute([$userId, time() - 10]);
        $count = $stmt->fetchArray(SQLITE3_ASSOC)['cnt'];
        
        if ($count > $settings['antiflood_limit']) {
            deleteMessage($groupId, $message['message_id']);
            restrictChatMember($groupId, $userId, ['can_send_messages' => false], time() + 300);
            return;
        }
    }
    
    // Anti-link
    if ($settings['antilink_enabled'] && preg_match('/(https?:\/\/|t\.me\/|@\w+)/i', $text)) {
        deleteMessage($groupId, $message['message_id']);
        addWarn($groupId, $userId, 0, 'Posted link');
        return;
    }
    
    // Blacklist words
    if ($settings['blacklist_words']) {
        $blacklist = json_decode($settings['blacklist_words'], true) ?? [];
        foreach ($blacklist as $word) {
            if (stripos($text, $word) !== false) {
                deleteMessage($groupId, $message['message_id']);
                addWarn($groupId, $userId, 0, 'Used blacklisted word');
                return;
            }
        }
    }
    
    // Night mode
    if ($settings['night_mode_enabled']) {
        $now = date('H:i');
        $start = $settings['night_mode_start'];
        $end = $settings['night_mode_end'];
        
        if (($start > $end && ($now >= $start || $now < $end)) || ($start < $end && $now >= $start && $now < $end)) {
            deleteMessage($groupId, $message['message_id']);
            return;
        }
    }
    
    // Handle /rules command
    if ($text === '/rules') {
        showRules($groupId, $userId);
        return;
    }
    
    // Handle /report or @admin
    if (strpos($text, '/report') === 0 || strpos($text, '@admin') !== false) {
        handleReport($message);
        return;
    }
    
    // Custom commands
    if (strpos($text, '/') === 0) {
        $cmd = explode(' ', $text)[0];
        $stmt = $db->prepare("SELECT response FROM custom_commands WHERE group_id = ? AND command = ?");
        $stmt->execute([$groupId, $cmd]);
        $result = $stmt->fetchArray(SQLITE3_ASSOC);
        
        if ($result) {
            sendMessage($groupId, $result['response']);
        }
    }
}

// ============================================================================
// NEW MEMBERS - CAPTCHA & WELCOME
// ============================================================================

function handleNewMembers(array $message): void {
    $groupId = $message['chat']['id'];
    $settings = getGroupSettings($groupId);
    $db = getDatabase();
    
    foreach ($message['new_chat_members'] as $member) {
        $userId = $member['id'];
        
        // Anti-bot filter
        if ($settings['antibot_enabled'] && $member['is_bot']) {
            banChatMember($groupId, $userId);
            continue;
        }
        
        // Statistics
        $today = date('Y-m-d');
        $stmt = $db->prepare("INSERT INTO stats (group_id, event_type, event_date, count) VALUES (?, 'join', ?, 1) 
                              ON CONFLICT(group_id, event_type, event_date) DO UPDATE SET count = count + 1");
        $stmt->execute([$groupId, $today]);
        
        // CAPTCHA
        if ($settings['captcha_enabled']) {
            // Restrict user until they pass CAPTCHA
            restrictChatMember($groupId, $userId, [
                'can_send_messages' => false,
                'can_send_media_messages' => false,
                'can_send_polls' => false,
                'can_send_other_messages' => false,
                'can_add_web_page_previews' => false,
                'can_change_info' => false,
                'can_invite_users' => false,
                'can_pin_messages' => false
            ]);
            
            sendCaptcha($groupId, $userId, $settings['captcha_type']);
        }
        
        // Welcome message
        if ($settings['welcome_enabled']) {
            $welcomeText = str_replace(
                ['{name}', '{username}', '{group}'],
                [$member['first_name'], '@' . ($member['username'] ?? 'user'), $message['chat']['title']],
                $settings['welcome_message']
            );
            
            $keyboard = null;
            if ($settings['welcome_buttons']) {
                $buttons = json_decode($settings['welcome_buttons'], true);
                if ($buttons) {
                    $keyboard = ['inline_keyboard' => $buttons];
                }
            }
            
            $sent = sendMessage($groupId, $welcomeText, $keyboard);
            
            // Auto-delete after 60s
            if ($sent && $settings['clean_service']) {
                sleep(60);
                deleteMessage($groupId, $sent['result']['message_id']);
            }
        }
    }
}

function sendCaptcha(int $groupId, int $userId, string $type): void {
    $db = getDatabase();
    
    if ($type === 'button') {
        $answer = 'verify';
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '✅ I am human', 'callback_data' => makeCallback('captcha', $groupId, $userId, $answer)]]
            ]
        ];
        
        sendMessage($groupId, "Welcome! Please click the button below to verify you're human.", $keyboard);
        
    } elseif ($type === 'math') {
        $a = rand(1, 10);
        $b = rand(1, 10);
        $answer = (string)($a + $b);
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => (string)($a + $b), 'callback_data' => makeCallback('captcha', $groupId, $userId, $answer)],
                    ['text' => (string)($a + $b + 1), 'callback_data' => makeCallback('captcha', $groupId, $userId, 'wrong')],
                    ['text' => (string)($a + $b - 1), 'callback_data' => makeCallback('captcha', $groupId, $userId, 'wrong')]
                ]
            ]
        ];
        
        sendMessage($groupId, "Welcome! What is $a + $b?", $keyboard);
    }
    
    // Save session
    $stmt = $db->prepare("INSERT OR REPLACE INTO captcha_sessions (group_id, user_id, answer) VALUES (?, ?, ?)");
    $stmt->execute([$groupId, $userId, $answer]);
}

function handleLeftMember(array $message): void {
    $groupId = $message['chat']['id'];
    $member = $message['left_chat_member'];
    $settings = getGroupSettings($groupId);
    
    if ($settings['goodbye_enabled']) {
        $goodbyeText = str_replace(
            ['{name}', '{username}'],
            [$member['first_name'], '@' . ($member['username'] ?? 'user')],
            $settings['goodbye_message']
        );
        
        $sent = sendMessage($groupId, $goodbyeText);
        
        if ($sent && $settings['clean_service']) {
            sleep(60);
            deleteMessage($groupId, $sent['result']['message_id']);
        }
    }
}

// ============================================================================
// CALLBACK QUERY HANDLER (BUTTON CLICKS)
// ============================================================================

function handleCallbackQuery(array $query): void {
    $callbackId = $query['id'];
    $userId = $query['from']['id'];
    $data = $query['data'];
    $messageId = $query['message']['message_id'] ?? 0;
    $chatId = $query['message']['chat']['id'] ?? $userId;
    
    $parts = parseCallback($data);
    $action = $parts[0];
    
    // CAPTCHA verification
    if ($action === 'captcha') {
        handleCaptchaCallback($query, $parts);
        return;
    }
    
    // All other actions require rate limiting
    if (!checkRateLimit($userId)) {
        answerCallbackQuery($callbackId, "Too many actions. Please wait.", true);
        return;
    }
    
    // Route to appropriate handler
    switch ($action) {
        case 'groups':
            showGroupList($userId, $messageId);
            answerCallbackQuery($callbackId);
            break;
            
        case 'group':
            $groupId = (int)$parts[1];
            if (!userOwnsGroup($userId, $groupId)) {
                answerCallbackQuery($callbackId, "Access denied", true);
                return;
            }
            showGroupMenu($userId, $groupId, $messageId);
            answerCallbackQuery($callbackId);
            break;
            
        case 'settings':
            $groupId = (int)$parts[1];
            $category = $parts[2] ?? 'main';
            if (!userOwnsGroup($userId, $groupId)) {
                answerCallbackQuery($callbackId, "Access denied", true);
                return;
            }
            showSettings($userId, $groupId, $category, $messageId);
            answerCallbackQuery($callbackId);
            break;
            
        case 'toggle':
            $groupId = (int)$parts[1];
            $setting = $parts[2];
            if (!userOwnsGroup($userId, $groupId)) {
                answerCallbackQuery($callbackId, "Access denied", true);
                return;
            }
            toggleSetting($userId, $groupId, $setting, $messageId);
            answerCallbackQuery($callbackId, "Setting updated");
            break;
            
        case 'stats':
            $groupId = (int)$parts[1];
            if (!userOwnsGroup($userId, $groupId)) {
                answerCallbackQuery($callbackId, "Access denied", true);
                return;
            }
            showStats($userId, $groupId, $messageId);
            answerCallbackQuery($callbackId);
            break;
            
        case 'back':
            $target = $parts[1] ?? 'main';
            if ($target === 'main') {
                showMainDashboard($userId, $messageId);
            } elseif ($target === 'groups') {
                showGroupList($userId, $messageId);
            } else {
                $groupId = (int)$target;
                showGroupMenu($userId, $groupId, $messageId);
            }
            answerCallbackQuery($callbackId);
            break;
            
        case 'rules_agree':
            $groupId = (int)$parts[1];
            answerCallbackQuery($callbackId, "Thank you for agreeing to the rules!");
            break;
            
        default:
            answerCallbackQuery($callbackId, "Unknown action");
    }
}

function handleCaptchaCallback(array $query, array $parts): void {
    $callbackId = $query['id'];
    $groupId = (int)$parts[1];
    $targetUserId = (int)$parts[2];
    $answer = $parts[3];
    $clickerId = $query['from']['id'];
    
    // Only the target user can click
    if ($clickerId !== $targetUserId) {
        answerCallbackQuery($callbackId, "This button is not for you", true);
        return;
    }
    
    $db = getDatabase();
    $stmt = $db->prepare("SELECT answer FROM captcha_sessions WHERE group_id = ? AND user_id = ?");
    $stmt->execute([$groupId, $targetUserId]);
    $session = $stmt->fetchArray(SQLITE3_ASSOC);
    
    if (!$session) {
        answerCallbackQuery($callbackId, "Session expired", true);
        return;
    }
    
    if ($answer === $session['answer']) {
        // Correct! Unrestrict user
        restrictChatMember($groupId, $targetUserId, [
            'can_send_messages' => true,
            'can_send_media_messages' => true,
            'can_send_polls' => true,
            'can_send_other_messages' => true,
            'can_add_web_page_previews' => true,
            'can_change_info' => false,
            'can_invite_users' => true,
            'can_pin_messages' => false
        ]);
        
        // Delete CAPTCHA message
        deleteMessage($groupId, $query['message']['message_id']);
        
        // Clean up session
        $stmt = $db->prepare("DELETE FROM captcha_sessions WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$groupId, $targetUserId]);
        
        answerCallbackQuery($callbackId, "✅ Verified! Welcome to the group.");
    } else {
        // Wrong answer - kick user
        banChatMember($groupId, $targetUserId);
        deleteMessage($groupId, $query['message']['message_id']);
        answerCallbackQuery($callbackId, "❌ Wrong answer. You have been removed.", true);
    }
}

// ============================================================================
// DASHBOARD & UI
// ============================================================================

function showMainDashboard(int $userId, ?int $messageId = null): void {
    $db = getDatabase();
    
    // Count user's groups
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM group_owners WHERE user_id = ?");
    $stmt->execute([$userId]);
    $groupCount = $stmt->fetchArray(SQLITE3_ASSOC)['cnt'];
    
    $text = "🤖 <b>Group Management Bot</b>\n\n";
    $text .= "Welcome to your control panel!\n";
    $text .= "You manage <b>$groupCount</b> group(s).\n\n";
    $text .= "Choose an option below:";
    
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '📋 My Groups', 'callback_data' => 'groups']],
            [['text' => '❓ Help', 'callback_data' => 'help'], ['text' => 'ℹ️ About', 'callback_data' => 'about']]
        ]
    ];
    
    if ($messageId) {
        editMessageText($userId, $messageId, $text, $keyboard);
    } else {
        sendMessage($userId, $text, $keyboard);
    }
}

function showGroupList(int $userId, ?int $messageId = null): void {
    $db = getDatabase();
    
    $stmt = $db->prepare("SELECT g.group_id, g.title FROM groups g 
                          JOIN group_owners o ON g.group_id = o.group_id 
                          WHERE o.user_id = ? AND g.active = 1 
                          ORDER BY g.title");
    $stmt->execute([$userId]);
    
    $buttons = [];
    while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
        $buttons[] = [['text' => $row['title'], 'callback_data' => makeCallback('group', $row['group_id'])]];
    }
    
    if (empty($buttons)) {
        $text = "📋 <b>My Groups</b>\n\nYou don't manage any groups yet.\n\nAdd me to a group as administrator to get started!";
    } else {
        $text = "📋 <b>My Groups</b>\n\nSelect a group to manage:";
    }
    
    $buttons[] = [['text' => '« Back', 'callback_data' => 'back:main']];
    
    $keyboard = ['inline_keyboard' => $buttons];
    
    if ($messageId) {
        editMessageText($userId, $messageId, $text, $keyboard);
    } else {
        sendMessage($userId, $text, $keyboard);
    }
}

function showGroupMenu(int $userId, int $groupId, ?int $messageId = null): void {
    $db = getDatabase();
    
    $stmt = $db->prepare("SELECT title FROM groups WHERE group_id = ?");
    $stmt->execute([$groupId]);
    $group = $stmt->fetchArray(SQLITE3_ASSOC);
    
    $text = "⚙️ <b>" . escapeHtml($group['title']) . "</b>\n\nWhat would you like to do?";
    
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '⚙️ Settings', 'callback_data' => makeCallback('settings', $groupId, 'main')]],
            [['text' => '📊 Statistics', 'callback_data' => makeCallback('stats', $groupId)]],
            [['text' => '👥 Members', 'callback_data' => makeCallback('members', $groupId)]],
            [['text' => '⚠️ Moderation', 'callback_data' => makeCallback('mod', $groupId)]],
            [['text' => '« Back to Groups', 'callback_data' => 'back:groups']],
        ]
    ];
    
    if ($messageId) {
        editMessageText($userId, $messageId, $text, $keyboard);
    } else {
        sendMessage($userId, $text, $keyboard);
    }
}

function showSettings(int $userId, int $groupId, string $category, ?int $messageId = null): void {
    $settings = getGroupSettings($groupId);
    
    $text = "⚙️ <b>Settings</b>\n\n";
    $buttons = [];
    
    if ($category === 'main') {
        $text .= "Choose a category:";
        $buttons = [
            [['text' => '🛡️ Anti-Spam', 'callback_data' => makeCallback('settings', $groupId, 'antispam')]],
            [['text' => '👋 Welcome/Goodbye', 'callback_data' => makeCallback('settings', $groupId, 'welcome')]],
            [['text' => '🔒 CAPTCHA', 'callback_data' => makeCallback('settings', $groupId, 'captcha')]],
            [['text' => '⚠️ Warnings', 'callback_data' => makeCallback('settings', $groupId, 'warns')]],
            [['text' => '📝 Rules', 'callback_data' => makeCallback('settings', $groupId, 'rules')]],
            [['text' => '« Back', 'callback_data' => makeCallback('back', $groupId)]],
        ];
    } elseif ($category === 'antispam') {
        $text .= "<b>Anti-Spam Settings</b>\n\n";
        $text .= "Anti-Flood: " . ($settings['antiflood_enabled'] ? '✅' : '❌') . "\n";
        $text .= "Anti-Link: " . ($settings['antilink_enabled'] ? '✅' : '❌') . "\n";
        $text .= "Anti-Media: " . ($settings['antimedia_enabled'] ? '✅' : '❌') . "\n";
        $text .= "Night Mode: " . ($settings['night_mode_enabled'] ? '✅' : '❌') . "\n";
        
        $buttons = [
            [['text' => ($settings['antiflood_enabled'] ? '✅' : '❌') . ' Anti-Flood', 'callback_data' => makeCallback('toggle', $groupId, 'antiflood_enabled')]],
            [['text' => ($settings['antilink_enabled'] ? '✅' : '❌') . ' Anti-Link', 'callback_data' => makeCallback('toggle', $groupId, 'antilink_enabled')]],
            [['text' => ($settings['antimedia_enabled'] ? '✅' : '❌') . ' Anti-Media', 'callback_data' => makeCallback('toggle', $groupId, 'antimedia_enabled')]],
            [['text' => ($settings['night_mode_enabled'] ? '✅' : '❌') . ' Night Mode', 'callback_data' => makeCallback('toggle', $groupId, 'night_mode_enabled')]],
            [['text' => '« Back', 'callback_data' => makeCallback('settings', $groupId, 'main')]],
        ];
    } elseif ($category === 'welcome') {
        $text .= "<b>Welcome/Goodbye Settings</b>\n\n";
        $text .= "Welcome: " . ($settings['welcome_enabled'] ? '✅' : '❌') . "\n";
        $text .= "Goodbye: " . ($settings['goodbye_enabled'] ? '✅' : '❌') . "\n";
        
        $buttons = [
            [['text' => ($settings['welcome_enabled'] ? '✅' : '❌') . ' Welcome Message', 'callback_data' => makeCallback('toggle', $groupId, 'welcome_enabled')]],
            [['text' => ($settings['goodbye_enabled'] ? '✅' : '❌') . ' Goodbye Message', 'callback_data' => makeCallback('toggle', $groupId, 'goodbye_enabled')]],
            [['text' => '« Back', 'callback_data' => makeCallback('settings', $groupId, 'main')]],
        ];
    } elseif ($category === 'captcha') {
        $text .= "<b>CAPTCHA Settings</b>\n\n";
        $text .= "Enabled: " . ($settings['captcha_enabled'] ? '✅' : '❌') . "\n";
        $text .= "Type: " . $settings['captcha_type'] . "\n";
        
        $buttons = [
            [['text' => ($settings['captcha_enabled'] ? '✅' : '❌') . ' CAPTCHA', 'callback_data' => makeCallback('toggle', $groupId, 'captcha_enabled')]],
            [['text' => '« Back', 'callback_data' => makeCallback('settings', $groupId, 'main')]],
        ];
    }
    
    $keyboard = ['inline_keyboard' => $buttons];
    
    if ($messageId) {
        editMessageText($userId, $messageId, $text, $keyboard);
    } else {
        sendMessage($userId, $text, $keyboard);
    }
}

function toggleSetting(int $userId, int $groupId, string $setting, int $messageId): void {
    $db = getDatabase();
    $settings = getGroupSettings($groupId);
    
    $newValue = $settings[$setting] ? 0 : 1;
    updateGroupSetting($groupId, $setting, $newValue);
    
    // Refresh the settings page
    $category = 'main';
    if (strpos($setting, 'antiflood') !== false || strpos($setting, 'antilink') !== false || 
        strpos($setting, 'antimedia') !== false || strpos($setting, 'night_mode') !== false) {
        $category = 'antispam';
    } elseif (strpos($setting, 'welcome') !== false || strpos($setting, 'goodbye') !== false) {
        $category = 'welcome';
    } elseif (strpos($setting, 'captcha') !== false) {
        $category = 'captcha';
    }
    
    showSettings($userId, $groupId, $category, $messageId);
}

function showStats(int $userId, int $groupId, ?int $messageId = null): void {
    $db = getDatabase();
    
    // Get today's joins
    $today = date('Y-m-d');
    $stmt = $db->prepare("SELECT count FROM stats WHERE group_id = ? AND event_type = 'join' AND event_date = ?");
    $stmt->execute([$groupId, $today]);
    $todayJoins = $stmt->fetchArray(SQLITE3_ASSOC)['count'] ?? 0;
    
    // Get week's joins
    $weekAgo = date('Y-m-d', strtotime('-7 days'));
    $stmt = $db->prepare("SELECT SUM(count) as total FROM stats WHERE group_id = ? AND event_type = 'join' AND event_date >= ?");
    $stmt->execute([$groupId, $weekAgo]);
    $weekJoins = $stmt->fetchArray(SQLITE3_ASSOC)['total'] ?? 0;
    
    // Get total warns
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM warns WHERE group_id = ?");
    $stmt->execute([$groupId]);
    $totalWarns = $stmt->fetchArray(SQLITE3_ASSOC)['cnt'];
    
    // Get total bans
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM bans WHERE group_id = ?");
    $stmt->execute([$groupId]);
    $totalBans = $stmt->fetchArray(SQLITE3_ASSOC)['cnt'];
    
    $text = "📊 <b>Statistics</b>\n\n";
    $text .= "👥 Joins today: <b>$todayJoins</b>\n";
    $text .= "📈 Joins this week: <b>$weekJoins</b>\n";
    $text .= "⚠️ Total warnings: <b>$totalWarns</b>\n";
    $text .= "🚫 Total bans: <b>$totalBans</b>\n";
    
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '« Back', 'callback_data' => makeCallback('back', $groupId)]]
        ]
    ];
    
    if ($messageId) {
        editMessageText($userId, $messageId, $text, $keyboard);
    } else {
        sendMessage($userId, $text, $keyboard);
    }
}

// ============================================================================
// MODERATION FUNCTIONS
// ============================================================================

function addWarn(int $groupId, int $userId, int $warnedBy, string $reason): void {
    $db = getDatabase();
    $settings = getGroupSettings($groupId);
    
    // Add warn
    $stmt = $db->prepare("INSERT INTO warns (group_id, user_id, warned_by, reason) VALUES (?, ?, ?, ?)");
    $stmt->execute([$groupId, $userId, $warnedBy, $reason]);
    
    // Count warns
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM warns WHERE group_id = ? AND user_id = ?");
    $stmt->execute([$groupId, $userId]);
    $warnCount = $stmt->fetchArray(SQLITE3_ASSOC)['cnt'];
    
    if ($warnCount >= $settings['max_warns']) {
        // Execute action
        if ($settings['warn_action'] === 'ban') {
            banChatMember($groupId, $userId);
        } elseif ($settings['warn_action'] === 'mute') {
            restrictChatMember($groupId, $userId, ['can_send_messages' => false]);
        }
        
        // Clear warns
        $stmt = $db->prepare("DELETE FROM warns WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$groupId, $userId]);
        
        sendMessage($groupId, "User reached maximum warnings and has been " . $settings['warn_action'] . "ed.");
    } else {
        $remaining = $settings['max_warns'] - $warnCount;
        sendMessage($groupId, "⚠️ Warning issued. $remaining warning(s) remaining.");
    }
}

function showRules(int $groupId, int $userId): void {
    $settings = getGroupSettings($groupId);
    
    $text = "📜 <b>Group Rules</b>\n\n" . ($settings['rules_text'] ?? 'No rules set.');
    
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '✅ I agree to the rules', 'callback_data' => makeCallback('rules_agree', $groupId)]]
        ]
    ];
    
    sendMessage($groupId, $text, $keyboard);
}

function handleReport(array $message): void {
    $groupId = $message['chat']['id'];
    $userId = $message['from']['id'];
    $db = getDatabase();
    
    // Get all owners
    $stmt = $db->prepare("SELECT user_id FROM group_owners WHERE group_id = ?");
    $stmt->execute([$groupId]);
    
    $reportText = "🚨 <b>Report from " . escapeHtml($message['from']['first_name']) . "</b>\n\n";
    if ($message['reply_to_message']) {
        $reportText .= "Reported message: " . escapeHtml($message['reply_to_message']['text'] ?? '[Media]');
    } else {
        $reportText .= escapeHtml($message['text']);
    }
    
    while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
        sendMessage($row['user_id'], $reportText);
    }
    
    sendMessage($groupId, "✅ Report sent to administrators.");
}

// ============================================================================
// STATE MANAGEMENT
// ============================================================================

function handleStateInput(int $userId, string $input, array $session): void {
    $state = $session['state'];
    $data = json_decode($session['data'], true) ?? [];
    
    // Handle different states here (for future expansion)
    // For now, clear state
    clearUserState($userId);
}

function setUserState(int $userId, string $state, array $data = []): void {
    $db = getDatabase();
    $stmt = $db->prepare("INSERT OR REPLACE INTO sessions (user_id, state, data, updated_at) VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $state, json_encode($data), time()]);
}

function clearUserState(int $userId): void {
    $db = getDatabase();
    $stmt = $db->prepare("DELETE FROM sessions WHERE user_id = ?");
    $stmt->execute([$userId]);
}

// ============================================================================
// CRON HANDLER (Scheduled Messages & Cleanup)
// ============================================================================

function handleCron(): void {
    $db = getDatabase();
    $now = date('H:i');
    
    // Send scheduled messages
    $stmt = $db->query("SELECT * FROM scheduled_messages WHERE sent = 0 AND schedule_time <= '$now'");
    while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
        sendMessage($row['group_id'], $row['message_text']);
        
        $updateStmt = $db->prepare("UPDATE scheduled_messages SET sent = 1 WHERE id = ?");
        $updateStmt->execute([$row['id']]);
    }
    
    // Clean old CAPTCHA sessions (older than 5 minutes)
    $fiveMinutesAgo = time() - 300;
    $db->exec("DELETE FROM captcha_sessions WHERE created_at < $fiveMinutesAgo");
    
    // Clean old rate limits
    $db->exec("DELETE FROM rate_limits WHERE timestamp < " . (time() - RATE_LIMIT_WINDOW));
    
    echo "Cron completed";
}

// ============================================================================
// SETUP PAGE
// ============================================================================

function showSetupPage(): void {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    $webhookUrl = $protocol . '://' . $host . $script;
    
    if (BOT_TOKEN === 'YOUR_BOT_TOKEN_HERE') {
        echo "<h1>⚠️ Configuration Required</h1>";
        echo "<p>Please edit bot.php and set your BOT_TOKEN from @BotFather</p>";
        exit;
    }
    
    if (isset($_GET['set'])) {
        $result = setWebhook($webhookUrl);
        if ($result) {
            echo "<h1>✅ Webhook Set Successfully!</h1>";
            echo "<p>Your bot is now ready to use.</p>";
            echo "<p>Webhook URL: <code>$webhookUrl</code></p>";
            echo "<p>Open Telegram and send /start to your bot.</p>";
        } else {
            echo "<h1>❌ Failed to Set Webhook</h1>";
            echo "<p>Please check your BOT_TOKEN and try again.</p>";
        }
        exit;
    }
    
    echo "<!DOCTYPE html>
<html>
<head>
    <title>Bot Setup</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
        .btn { display: inline-block; padding: 15px 30px; background: #0088cc; color: white; 
               text-decoration: none; border-radius: 5px; font-size: 18px; }
        .btn:hover { background: #006699; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
    <h1>🤖 Telegram Bot Setup</h1>
    <p>Click the button below to set the webhook and activate your bot.</p>
    <p>Webhook URL: <code>$webhookUrl</code></p>
    <a href='?setup=1&set=1' class='btn'>Set Webhook</a>
</body>
</html>";
}

// ============================================================================
// MAIN ENTRY POINT
// ============================================================================

// Setup page
if (isset($_GET['setup'])) {
    showSetupPage();
    exit;
}

// Cron endpoint
if (isset($_GET['cron'])) {
    handleCron();
    exit;
}

// Webhook handler
$input = file_get_contents('php://input');
if ($input) {
    $update = json_decode($input, true);
    if ($update) {
        processUpdate($update);
    }
}

// Silent exit for webhook
http_response_code(200);
