# Modular Telegram Group Management Bot

A production-ready, feature-rich Telegram bot built with a clean, modular architecture following software engineering best practices.

## Features

### 🛡️ Moderation & Security
- **Anti-Flood**: Adaptive rate limiting to prevent message spam
- **Anti-Link**: Automatically delete links (configurable per group)
- **Anti-Media**: Block media messages if enabled
- **Anti-Bot**: Automatically ban bot accounts
- **CAPTCHA System**: Verify new members (button or math CAPTCHA)
- **Night Mode**: Restrict messaging during specified hours
- **Blacklist Words**: Auto-delete messages containing blacklisted words
- **Warning System**: Warn users before taking action (ban/mute/kick)
- **Risk Scoring**: Analyze messages for spam potential

### 👥 User Management
- **Welcome Messages**: Customizable welcome messages with placeholders
- **Goodbye Messages**: Farewell messages when users leave
- **Reputation System**: Users earn reputation points
- **Whitelist**: Exempt users from moderation rules
- **Reporting System**: Users can report messages to admins (`/report` or `@admin`)

### 📊 Analytics & Statistics
- Track joins, messages, warnings, and bans
- Daily and weekly statistics
- Group activity reports

### 🤖 Automation
- **Scheduled Messages**: Send messages at specified times (via cron)
- **Automated Cleanup**: Remove old CAPTCHA sessions and rate limits
- **Custom Commands**: Create custom commands per group

### ⚙️ Administration
- **Multi-tenant**: Each user manages only their own groups
- **Dashboard**: Interactive menu system for group management
- **Settings Management**: Configure all features via inline keyboards
- **Audit Logs**: Track all moderation actions

### 🔌 Integrations
- Weather information (`/weather [city]`)
- Cryptocurrency prices (`/price`)
- Payment/subscription prompts (`/subscribe`)

## Architecture

### Core Principles
- **PSR-4 Autoloading**: Standard PHP autoloading
- **Dependency Injection**: Service container for dependency management
- **Plugin System**: Modular architecture with event-driven plugins
- **Service Layer**: Business logic separated into services
- **Model Layer**: Data access abstraction via models
- **Error Handling**: Comprehensive error logging and recovery

### Directory Structure
```
src/
├── Core/              # Core framework (Bot, Container, Config, PluginManager)
├── Database/          # Database abstraction and schema
├── Models/            # Data models (User, Group, etc.)
├── Modules/           # Feature modules (plugins)
├── Services/          # Business logic services
├── Telegram/          # Telegram API client
└── Interfaces/        # Contracts/interfaces

public/
└── webhook.php        # Webhook entry point

storage/
├── logs/              # Application logs
└── database.sqlite    # SQLite database (if used)
```

### Database Support
- **SQLite**: Default, perfect for small to medium deployments
- **MySQL**: Supported for production and heavy loads

Both databases are automatically initialized with the correct schema.

## Installation

### Requirements
- PHP 7.4 or higher
- Composer
- SQLite extension OR MySQL/MariaDB
- HTTPS hosting (required for Telegram webhooks)

### Setup Steps

1. **Clone or download the repository**
   ```bash
   cd telegram-manage-group-bot
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Configure environment**
   ```bash
   cp .env.example .env
   # Edit .env and add your BOT_TOKEN from @BotFather
   ```

4. **Set up webhook**
   
   Point your Telegram bot webhook to:
   ```
   https://yourdomain.com/public/webhook.php
   ```
   
   You can use Telegram's API directly:
   ```bash
   curl -X POST "https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook" \
     -d "url=https://yourdomain.com/public/webhook.php"
   ```

5. **Set up cron job** (for scheduled messages and cleanup)
   ```bash
   # Add to crontab (runs every minute)
   * * * * * curl -s https://yourdomain.com/public/webhook.php?cron=1 > /dev/null
   ```

6. **Test the bot**
   - Send `/start` to your bot in a private chat
   - Add the bot to a group and make it an administrator
   - Use `/start` again to access the group management dashboard

## Configuration

### Environment Variables (.env)

```env
# Required
BOT_TOKEN=your_bot_token_from_botfather

# Database (SQLite - default)
DB_CONNECTION=sqlite
DB_PATH=storage/database.sqlite

# Database (MySQL - alternative)
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=telegram_bot
# DB_USERNAME=root
# DB_PASSWORD=your_password

# Optional
LOG_CHANNEL_ID=telegram_channel_id_for_error_logs
HTTP_PROXY=proxy_url_if_needed
```

## Usage

### For Group Administrators

1. **Add bot to group**: Add the bot to your Telegram group and promote it to administrator

2. **Access dashboard**: Send `/start` to the bot in private chat to see your groups

3. **Configure settings**: Use the inline keyboard to navigate and configure:
   - Moderation settings (anti-flood, anti-link, etc.)
   - Welcome/goodbye messages
   - CAPTCHA settings
   - Warning system
   - Rules

### Commands

#### Group Commands (in group chat)
- `/rules` - Show group rules
- `/report [reason]` - Report a message (reply to message to report it)
- `/stats` - Show group statistics (admin only)
- `/weather [city]` - Get weather information
- `/price` - Get cryptocurrency prices

#### Private Commands
- `/start` - Open main dashboard
- `/mygroups` - List all your groups
- `/help` - Show help message

## Development

### Adding a New Module

1. Create a new class in `src/Modules/` implementing `PluginInterface`:

```php
<?php
namespace App\Modules;

use App\Interfaces\PluginInterface;
use App\Core\Container;

class MyModule implements PluginInterface {
    public function register(Container $container): void {
        // Register services, bind dependencies
    }

    public function boot(Container $container): void {
        // Initialize module
    }

    public function getListeners(): array {
        return [
            'update.received' => 'handleUpdate'
        ];
    }

    public function handleUpdate(array $update, Container $container): void {
        // Handle update
    }
}
```

2. Register the module in `src/Core/Bot.php`:

```php
$pm->register(\App\Modules\MyModule::class);
```

### Available Services

- `Database`: Database access
- `Client`: Telegram API client
- `SettingsService`: Group settings management
- `AuthorizationService`: Permission checking
- `Logger`: Logging service

### Events

Modules can listen to events:
- `update.received`: Fired when a Telegram update is received

## Testing

Run the test API script to verify external integrations:
```bash
php test_api.php
```

## Troubleshooting

### Bot not responding
1. Check that webhook is set correctly
2. Verify BOT_TOKEN in .env
3. Check `storage/logs/error.log` for errors
4. Ensure PHP has curl extension enabled

### Database errors
1. Ensure database file is writable (SQLite) or MySQL credentials are correct
2. Check file permissions on storage directory

### CAPTCHA not working
1. Ensure bot has permission to restrict members
2. Check that CAPTCHA is enabled in group settings

## Security Considerations

- Never commit `.env` file with real tokens
- Keep bot token secure
- Regularly update dependencies
- Monitor logs for suspicious activity
- Use HTTPS for webhook endpoint

## License

MIT License - See LICENSE file for details

## Contributing

1. Follow PSR-12 coding standards
2. Add proper error handling
3. Write tests for new features
4. Update documentation

## Support

For issues and questions, please check the error logs in `storage/logs/error.log`.
