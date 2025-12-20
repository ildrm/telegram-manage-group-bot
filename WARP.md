# WARP.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

## Quick commands (PowerShell)

### Install dependencies

```powershell
composer install
```

### Refresh Composer autoload

```powershell
composer dump-autoload -o
```

### Run the webhook handler locally (for development)

This repo’s modular bot entrypoint is `public/webhook.php`.

```powershell
php -S localhost:8000 -t public
```

Notes:
- Telegram webhooks require a publicly reachable HTTPS URL; for local dev you’ll need a tunnel (not included in this repo).
- Errors from the webhook entrypoint are appended to `storage/logs/error.log`.

### Smoke-test external APIs used by integrations

```powershell
php .\test_api.php
```

### Syntax check a file

```powershell
php -l .\public\webhook.php
```

### “Single test” / diagnostics

In Telegram, the modular bot includes a `/test` command (admin-only, controlled by `ADMIN_IDS` in `.env`) implemented in `src/Modules/TestModule.php`.

## Repository entrypoints

- `public/webhook.php`: modular/PSR-4 entrypoint used by the README deployment instructions. It instantiates `App\Core\Bot` and calls `run()`.
- `telegram_group_bot.php`: a separate “single-file edition” bot implementation with its own config constants and its own setup/cron query parameters. It is not used by `public/webhook.php`.

When making changes, be explicit about which implementation you’re modifying.

## Configuration

- Configuration is loaded by `src/Core/Config.php` from `.env` (fallback: `.env.example`).
- The env parser is intentionally simple (`KEY=VALUE` per line; no quoting/escaping support).

Key settings (see `.env.example`):
- `BOT_TOKEN`
- Database: `DB_CONNECTION` (`mysql` or `sqlite`), plus `DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD` or `DB_PATH`
- Logging: `LOG_CHANNEL_ID`
- Admin: `ADMIN_IDS` (comma-separated)

## High-level architecture (modular bot)

### Request flow

1. `public/webhook.php` loads Composer autoload and runs `App\Core\Bot`.
2. `App\Core\Bot` wires up a small DI container (`src/Core/Container.php`) and singletons:
   - `Config`
   - `Database` (PDO wrapper)
   - `Telegram\Client` (Telegram API wrapper)
   - `Services\Logger`
   - `Core\PluginManager`
3. `Bot::run()` reads the JSON update from `php://input` and dispatches a single event:
   - `update.received` with the decoded update payload.
4. `Core\PluginManager` fans out that event to all registered modules (plugins).

### Plugin system

- Plugins implement `src/Interfaces/PluginInterface.php`.
- A plugin registers event listeners via `getListeners()`, mapping `event_name => methodName`.
- Plugins are registered (and order-defined) in `src/Core/Bot.php` (`loadPlugins()`).

Most modules currently subscribe to the same event:
- `update.received`: core routing, moderation, captcha, rules, integrations, etc.

There is also an event used by the scheduler module:
- `cron.tick` (listened to by `src/Modules/AutomationModule.php`)

…but the modular entrypoint currently does not dispatch `cron.tick`.

### Core “modules” (big picture)

- `src/Modules/CoreModule.php`: high-level routing for `/start`, `/help`, `/mygroups`, callback-based dashboard UX, and “bot added to group” registration via `my_chat_member`.
- `src/Modules/ModerationModule.php`: basic flood/link/spam heuristics (currently partly stubbed; many settings are not yet read from DB).
- `src/Modules/CaptchaModule.php`: restricts new members and verifies via callback button.
- `src/Modules/RuleModule.php` + `src/Services/RuleEvaluator.php`: loads rules from `storage/rules.json` and evaluates simple field-equality conditions.
- `src/Modules/IntegrationModule.php`: `/weather` (Open-Meteo) and `/price` (CoinGecko) + callback-based crypto menu.
- `src/Modules/AnalyticsModule.php`: tracks joins/messages into a `stats` table (see note below about schema drift).
- `src/Modules/TestModule.php`: `/test` admin-only self-diagnostic.

## Data model and schema drift

The modular bot uses:
- `src/Database/Database.php`: PDO wrapper with helper methods for MySQL-vs-SQLite differences.
- `src/Database/Schema.php`: auto-creates a subset of tables at runtime.

There is also a separate schema file:
- `database.sql`: MySQL schema including additional tables (e.g. `stats`) that some modules expect.

Important: `Schema.php` currently does not create every table referenced by modules (notably `stats` used by `AnalyticsModule`). If you touch analytics/scheduler features, verify the table exists and decide whether to:
- expand `Schema.php`,
- or treat `database.sql` as the source of truth for MySQL and keep `Schema.php` minimal.

## Logging and runtime artifacts

- `storage/logs/error.log`: errors caught by `public/webhook.php`.
- `storage/logs/app.log`: written by `src/Services/Logger.php`.
- `storage/rules.json`: rule definitions used by `RuleModule`.

## Cron / scheduler note

`README.md` describes hitting `public/webhook.php?cron=1` every minute.

The modular entrypoint currently ignores query parameters and only processes POSTed Telegram updates. If cron support is required, a typical approach is to extend `public/webhook.php` (or `App\Core\Bot`) to detect `?cron=1` and dispatch `cron.tick` via `PluginManager`.