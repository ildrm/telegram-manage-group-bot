# Legacy Single-File Implementation

The file `telegram_group_bot.php` is a legacy single-file implementation of the bot. This was the original implementation before the project was refactored into a modular architecture.

## Current Status

**This file is deprecated and should not be used in production.**

The current, recommended implementation uses the modular architecture located in the `src/` directory and is accessed via `public/webhook.php`.

## Why Keep This File?

- **Reference**: Useful for understanding the original implementation
- **Quick Testing**: Can be used for quick tests without the full modular setup
- **Migration Guide**: Helps understand differences between old and new implementations

## Differences from Modular Version

### Architecture
- **Legacy**: Single file with global functions
- **Current**: Modular architecture with classes, services, and dependency injection

### Database
- **Legacy**: Direct SQLite3 usage
- **Current**: PDO abstraction supporting both SQLite and MySQL

### Error Handling
- **Legacy**: Basic error handling
- **Current**: Comprehensive error logging and recovery

### Code Organization
- **Legacy**: All code in one file
- **Current**: Organized into modules, services, and models

## Migration

If you're using the legacy file, migrate to the modular version:

1. Stop using `telegram_group_bot.php` as webhook endpoint
2. Update webhook to point to `public/webhook.php`
3. Ensure `.env` file is configured
4. Run `composer install` if not already done
5. Database schema will be automatically migrated on first run

The database schema is compatible, so your existing data will work with the new implementation.

## Removing This File

If you want to remove this file:

1. Ensure you've migrated to the modular version
2. Verify everything works correctly
3. You can safely delete `telegram_group_bot.php`

We recommend keeping it in the repository for reference, but you can add it to `.gitignore` if you want to exclude it from deployments.
