# Modular Telegram Group Management Bot

A production-ready, feature-rich Telegram bot built with a modular architecture.

## Features

### 🛡️ Moderation & Security
-   **Risk Scoring**: Analyzes messages for spam potential (all-caps, emoji spam).
-   **Anti-Flood**: Adaptive rate limiting.
-   **CAPTCHA**: Verify new members before they can chat.

### 💾 Database Support
-   **MySQL** (Default): Recommended for production and heavy loads.
-   **SQLite**: Supported for simple setups or testing.

### 📈 Engagement & User Lifecycle
-   **Reputation System**: Users earn rep by helping others (`+`, `thx`).
-   **Analytics**: Track joins and message activity.
-   **Welcomer**: Customizable welcome/goodbye messages.

### 🤖 Automation
-   **Scheduler**: Run cron jobs for cleanup and scheduled messages.
-   **Integrations**: Weather (`/weather`), Crypto (`/price`).
-   **Payments**: Subscription prompts (`/subscribe`).

## Deployment

1.  Run `composer install`.
2.  Configure `.env`.
3.  Set Webhook to `public/webhook.php`.
4.  Set up a Cron job to hit `public/webhook.php?cron=1` every minute for the Scheduler.

## Architecture
-   **PSR-4** Autoloading.
-   **Dependency Injection** Container.
-   **Plugin System** for easy extension.
-   **SQLite** Database with Wal mode.
