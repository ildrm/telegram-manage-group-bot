# Telegram Group Management Bot

A single-file, production-ready Telegram bot (PHP) for **managing groups** with a privacy‑first, multi‑tenant architecture.

Each Telegram user gets their **own control panel** in a private chat with the bot and can only see and manage the groups where they added the bot. All configuration and state are stored in a local SQLite database.

---

## Features

### Group onboarding & control panel

- Webhook-based Telegram bot (no long polling needed).
- Private control panel for each user:
  - `/start` opens the main dashboard.
  - Shows how many groups the user owns.
  - “My Groups” list with per‑group management menus.
- Automatic group registration:
  - When the bot is added to a group, it:
    - Stores basic group info (ID, title, username).
    - Registers the user who added the bot as the group owner.
  - When the bot is removed, the group is marked as inactive.

### Anti‑spam & security

- Per‑group settings stored in the `settings` table:
  - Anti‑flood (`antiflood_enabled` / `antiflood_limit`).
  - Anti‑link (`antilink_enabled`) – deletes messages containing URLs, t.me links, or @mentions.
  - Anti‑media (`antimedia_enabled`) – restricts messages with media (photos, videos, etc.).
  - Anti‑bot (`antibot_enabled`) – protection against other bots.
  - Night mode (`night_mode_enabled`, `night_mode_start`, `night_mode_end`) – optionally deletes all messages during specified hours.
  - Blacklisted words – deletes messages that contain listed terms.
  - Whitelist – bypass filters for trusted users.
- Rate limiting per user (globally):
  - `RATE_LIMIT_ACTIONS` and `RATE_LIMIT_WINDOW` cap how many actions each user can perform in a time window.
  - All bot interactions pass through a central `isRateLimited()` check.

### Onboarding & system messages

- Welcome & goodbye messages:
  - `welcome_enabled` / `welcome_message`.
  - `goodbye_enabled` / `goodbye_message`.
  - Placeholders supported in welcome text:
    - `{name}` – the user’s first name.
    - `{username}` – `@username` or a fallback.
    - `{group}` – group title.
  - Optional inline buttons for welcome messages (`welcome_buttons`).
- Optional cleaning of Telegram “service messages” (e.g., join/leave notices).

### CAPTCHA gate for new members

- Optional CAPTCHA challenge for new members:
  - `captcha_enabled` (on/off).
  - `captcha_type` (currently `button`).
- When enabled:
  - New members are **restricted** immediately on join (cannot send messages/media).
  - Bot sends a CAPTCHA (e.g. button-based).
  - On correct answer, restrictions are lifted and the user is allowed to chat.
  - CAPTCHA sessions are stored in `captcha_sessions` and cleaned up by cron.

### Automatic warning system

- Configurable warning system per group:
  - `max_warns` – maximum number of warnings.
  - `warn_action` – what to do when the user reaches the limit (e.g. `mute` or `ban`).
- Automatic warning triggers:
  - Posting links when `antilink_enabled` is on.
  - Using blacklisted words.
- When limit is reached:
  - User is automatically muted or banned based on `warn_action`.
  - All existing warnings for that user in that group are cleared.
- All warnings are stored in the `warns` table.

### Group commands for members

- `/rules` – shows the group rules text configured in settings (`rules_text`).
- `/report <reason>` or `@admin` – sends a report to group administrators using the bot.
- Custom commands:
  - Admins can define custom `/command` → static response pairs per group.
  - When a message starting with `/something` is seen:
    - The bot looks for it in `custom_commands`.
    - If found, the configured response is sent.

### Statistics & scheduled tasks

- Join statistics:
  - The bot stores per‑day join counts in the `stats` table.
  - The “Statistics” section in the group menu shows:
    - Today’s joins.
    - Aggregate join counts over a recent period.
- Scheduled messages:
  - Simple per‑group scheduled text messages stored in `scheduled_messages`.
  - A lightweight cron handler (`?cron=1`) sends any pending scheduled messages whose `schedule_time` is due.

### Multi‑tenant & privacy

- Each user has their own **independent** view:
  - `group_owners` table defines who owns which groups.
  - All management actions validate ownership via `userOwnsGroup()`.
- No cross‑group or cross‑user leakage:
  - Users can only see and modify groups they own.
  - Group‑level settings and statistics are scoped by group ID.

---

## Requirements

- **PHP** 7.4+
- **Extensions**:
  - `sqlite3`
  - `json`
  - `curl`
  - `openssl`
- A public **HTTPS** URL where Telegram can reach the bot (required for webhooks).
- A Telegram Bot token from [@BotFather](https://t.me/BotFather).

---

## Configuration

All configuration is at the top of the PHP file in the **CONFIGURATION** section:

```php
// CONFIGURATION

define('BOT_TOKEN', 'YOUR_BOT_TOKEN_HERE'); // Get from @BotFather
define('ADMIN_IDS', [123456789]);          // Your Telegram user ID(s) for testing/support
define('DB_FILE', __DIR__ . '/database.sqlite');

// Rate limiting
define('RATE_LIMIT_ACTIONS', 30); // Max actions per user
define('RATE_LIMIT_WINDOW', 60);  // Window in seconds
```

1. **Set `BOT_TOKEN`**  
   - Create a bot with @BotFather.
   - Copy the token and paste it into `BOT_TOKEN`.

2. **Set `ADMIN_IDS`**  
   - Put your own Telegram user ID in this array for testing/support features.
   - You can find your user ID using bots like `@userinfobot`.

3. **Database file path (`DB_FILE`)**  
   - By default, a `database.sqlite` file is created alongside the PHP script.
   - Ensure that the directory is **writable** by the web server user.

4. **Rate limiting** (optional tuning)  
   - `RATE_LIMIT_ACTIONS`: total allowed actions per window per user.
   - `RATE_LIMIT_WINDOW`: length of the time window in seconds.

---

## Installation & Setup

1. **Upload the script**

   Upload `telegram_group_bot.php` (or your chosen filename) to your HTTPS‑enabled web server, for example:

   ```text
   /var/www/html/bot.php
   ```

2. **File permissions**

   Ensure the web server can write to:

   - The directory where `DB_FILE` resides.
   - Any directories where you may store logs (if you add logging).

   For example (adjust user/group as needed):

   ```bash
   chown www-data:www-data /var/www/html
   chmod 750 /var/www/html
   ```

3. **Configure the bot**

   Edit the configuration block near the top of the script:

   - Set `BOT_TOKEN`.
   - Set `ADMIN_IDS`.
   - Optionally change `DB_FILE` and rate limit constants.

4. **Run the built‑in setup page**

   In your browser, open:

   ```text
   https://yourdomain.com/bot.php?setup=1
   ```

   The setup page will:

   - Show the detected webhook URL.
   - Offer a “Set Webhook” button.
   - Validate that `BOT_TOKEN` is not left as the placeholder.

   Clicking **Set Webhook** calls Telegram’s `setWebhook` API and, on success, the bot is ready.

5. **Add the bot to a group**

   - In Telegram, add your bot to a group or supergroup.
   - Promote the bot to **administrator** and give it the necessary permissions:
     - Delete messages
     - Restrict members
     - Invite users (optional)
     - Pin messages (optional)

   The user who adds the bot becomes the **owner** of that group inside the bot.

6. **Open the private control panel**

   - Start a private chat with your bot.
   - Send `/start`.
   - You will see the main dashboard with:
     - Total number of groups you own.
     - Buttons for “My Groups” and other options.

---

## Usage

### As a group owner

1. **Open the dashboard**

   - In private chat with the bot, send `/start`.
   - Use “📋 My Groups” to see all groups where you are an owner.

2. **Select a group**

   - Tap on a group from the list.
   - You will see a per‑group menu with actions such as:
     - ⚙️ Settings
     - 📊 Statistics
     - 👥 Members (depending on your version)
     - ⚠️ Moderation
     - Back to groups

3. **Configure settings**

   - Under **Settings** you can access several categories:
     - **🛡️ Anti‑Spam** – flood limit, links, media, night mode, etc.
     - **👋 Welcome/Goodbye** – enable/disable and customize texts.
     - **🔒 CAPTCHA** – enable/disable and select type.
     - **⚠️ Warnings** – set `max_warns` and `warn_action`.
     - **📝 Rules** – set the text used by `/rules`.
   - Most on/off options are toggled via inline buttons:
     - The button label shows `✅` or `❌` to indicate current status.

4. **Scheduled messages**

   - From the group menu you can configure scheduled messages (where provided).
   - Scheduled messages are stored in `scheduled_messages` and sent when the cron endpoint runs.

5. **Moderation**

   - The bot automatically:
     - Deletes messages that break rules (links, blacklisted words, etc.).
     - Issues warnings and escalates to `mute` or `ban` when `max_warns` is exceeded.
   - You can also use whitelists to exclude trusted users from filters.

### In the group (for members)

- `/rules`  
  Shows the rules text configured by the admins.

- `/report <reason>` or mentioning `@admin`  
  Sends a report that the bot forwards to the group admins/owners.

- Custom slash commands  
  Admins can define static text commands such as:

  - `/faq`
  - `/links`
  - `/support`

  When a member sends one of these, the bot responds with the configured text.

---

## Cron endpoint

The bot provides a lightweight cron endpoint for scheduled tasks:

```text
https://yourdomain.com/bot.php?cron=1
```

`handleCron()` currently:

- Sends any **scheduled messages** whose `schedule_time` is due.
- Cleans old CAPTCHA sessions (older than 5 minutes).
- Cleans old rate‑limit entries.

Set up a system cron job to hit this endpoint periodically, for example:

```bash
* * * * * curl -fsS "https://yourdomain.com/bot.php?cron=1" >/dev/null 2>&1
```

Running it every minute is usually sufficient.

---

## Database schema (high level)

All data is stored in a single SQLite database (`DB_FILE`).  
Tables are created automatically by `initializeDatabase()` on first use.

Key tables:

- `users`
  - Basic metadata about Telegram users (ID, username, names, created_at).
- `groups`
  - List of managed groups with title, username, and active flag.
- `group_owners`
  - Mapping between users and groups they own/manage.
- `settings`
  - Per‑group configuration flags (welcome, goodbye, CAPTCHA, anti‑spam, warns, rules, etc.).
- `bans`
  - Records of banned users per group (structure prepared for extended use).
- `warns`
  - Warnings issued to users, with reason and timestamps.
- `captcha_sessions`
  - Active CAPTCHA states for new members.
- `scheduled_messages`
  - Messages scheduled to be sent to groups at specific times.
- `custom_commands`
  - Per‑group mapping of `/command` → response text.
- `stats`
  - Simple per‑day counters for events like `join`.
- `rate_limits`
  - Per‑user action timestamps for rate limiting.
- `whitelist`
  - Whitelisted users per group (bypass filters).
- `sessions`
  - Per‑user conversational state for multi‑step flows in private chat.

You can back up the bot simply by copying the SQLite file:

```bash
cp database.sqlite database_backup.sqlite
```

---

## Security notes

- Always host the bot over **HTTPS**.
- Keep `BOT_TOKEN` secret:
  - Do not commit it to public repositories.
  - Use environment variables or private configuration management if possible.
- Make sure `DB_FILE` and any future log files:
  - Are not directly exposed via the web server.
  - Have restrictive filesystem permissions.
- Limit who can promote the bot to administrator in groups.
- Consider restricting access to the setup (`?setup=1`) and cron (`?cron=1`) URLs at the web server or firewall level.

---

## Extending the bot

The script is intentionally structured as a single file with clear sections:

- **Configuration**
- **Security & helpers**
- **Database initialization**
- **Telegram API wrappers**
- **Callback and message handlers**
- **Business logic (settings, moderation, stats)**
- **Cron handler**
- **Setup page & main entry point**

You can extend it by:

- Adding new settings categories and toggles.
- Adding new moderation rules (e.g. anti‑forwarding, language restrictions).
- Integrating logging (file or external service).
- Surfacing more statistics (messages per day, most active users).
- Implementing advanced backup/restore logic around the SQLite database.

Because everything is local to one file and one database, deployment and updates remain simple.

---

## License

If not stated otherwise in the file header, you can treat this bot as MIT‑style licensed for your own projects.  
Adjust or add a dedicated `LICENSE` file according to your distribution needs.
