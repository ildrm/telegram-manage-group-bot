<?php
/**
 * Basic Integration Tests for Telegram Bot
 * 
 * Note: These are basic tests. For full test suite, install PHPUnit:
 * composer require --dev phpunit/phpunit
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Bot;
use App\Core\Container;
use App\Core\Config;
use App\Database\Database;
use App\Telegram\Client;

class BotTest {
    private $container;

    public function __construct() {
        // Initialize bot to get container
        $bot = new Bot();
        $this->container = $bot->getContainer();
    }

    public function testContainer(): bool {
        echo "Testing Container...\n";
        try {
            $config = $this->container->get(Config::class);
            echo "✓ Container returns Config\n";
            return true;
        } catch (\Exception $e) {
            echo "✗ Container test failed: " . $e->getMessage() . "\n";
            return false;
        }
    }

    public function testDatabase(): bool {
        echo "\nTesting Database...\n";
        try {
            $db = $this->container->get(Database::class);
            echo "✓ Database connection successful\n";
            
            // Test query
            $stmt = $db->query("SELECT 1 as test");
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($result && $result['test'] == 1) {
                echo "✓ Database query works\n";
                return true;
            }
            echo "✗ Database query failed\n";
            return false;
        } catch (\Exception $e) {
            echo "✗ Database test failed: " . $e->getMessage() . "\n";
            return false;
        }
    }

    public function testSettingsService(): bool {
        echo "\nTesting Settings Service...\n";
        try {
            $settings = $this->container->get(\App\Services\SettingsService::class);
            echo "✓ SettingsService initialized\n";
            
            // Test getting default settings
            $testGroupId = -999999; // Non-existent group
            $defaults = $settings->getSettings($testGroupId);
            if (isset($defaults['welcome_enabled'])) {
                echo "✓ SettingsService creates defaults\n";
                return true;
            }
            echo "✗ SettingsService failed to create defaults\n";
            return false;
        } catch (\Exception $e) {
            echo "✗ SettingsService test failed: " . $e->getMessage() . "\n";
            return false;
        }
    }

    public function testClientInitialization(): bool {
        echo "\nTesting Telegram Client...\n";
        try {
            $client = $this->container->get(Client::class);
            echo "✓ Client initialized\n";
            
            // Test getMe (requires valid token)
            $config = $this->container->get(Config::class);
            $token = $config->get('BOT_TOKEN');
            if ($token && $token !== 'YOUR_BOT_TOKEN_HERE') {
                $me = $client->getMe();
                if ($me && isset($me['result'])) {
                    echo "✓ Bot token is valid\n";
                    echo "  Bot username: @" . ($me['result']['username'] ?? 'unknown') . "\n";
                    return true;
                } else {
                    echo "⚠ Bot token may be invalid\n";
                }
            } else {
                echo "⚠ BOT_TOKEN not configured, skipping API test\n";
            }
            return true;
        } catch (\Exception $e) {
            echo "✗ Client test failed: " . $e->getMessage() . "\n";
            return false;
        }
    }

    public function testSchema(): bool {
        echo "\nTesting Database Schema...\n";
        try {
            $db = $this->container->get(Database::class);
            
            $tables = ['users', 'groups', 'settings', 'warns', 'bans', 'stats'];
            $driver = $db->getDriver();
            
            foreach ($tables as $table) {
                if ($driver === 'sqlite') {
                    $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
                } else {
                    $stmt = $db->query("SHOW TABLES LIKE '$table'");
                }
                
                if ($stmt->fetch()) {
                    echo "✓ Table '$table' exists\n";
                } else {
                    echo "✗ Table '$table' missing\n";
                    return false;
                }
            }
            return true;
        } catch (\Exception $e) {
            echo "✗ Schema test failed: " . $e->getMessage() . "\n";
            return false;
        }
    }

    public function runAll(): void {
        echo "========================================\n";
        echo "Telegram Bot Integration Tests\n";
        echo "========================================\n\n";

        $results = [
            $this->testContainer(),
            $this->testDatabase(),
            $this->testSchema(),
            $this->testSettingsService(),
            $this->testClientInitialization(),
        ];

        $passed = count(array_filter($results));
        $total = count($results);

        echo "\n========================================\n";
        echo "Results: $passed/$total tests passed\n";
        echo "========================================\n";
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli') {
    $tester = new BotTest();
    $tester->runAll();
}
