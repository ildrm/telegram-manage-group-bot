<?php
namespace App\Core;

use App\Database\Database;
use App\Telegram\Client;

class Bot {
    private Container $container;

    public function __construct() {
        $this->container = new Container();
        $this->bootstrap();
    }

    private function bootstrap(): void {
        // Bind Config
        $this->container->singleton(Config::class, function() {
            return new Config();
        });

        // Bind Database
        $this->container->singleton(Database::class, function($c) {
            $dbConfig = [
                'driver' => $config->get('DB_CONNECTION', 'sqlite'),
                'host' => $config->get('DB_HOST', '127.0.0.1'),
                'port' => $config->get('DB_PORT', '3306'),
                'database' => $config->get('DB_DATABASE', 'telegram_bot'),
                'username' => $config->get('DB_USERNAME', 'root'),
                'password' => $config->get('DB_PASSWORD', ''),
                'path' => dirname(dirname(__DIR__)) . '/' . $config->get('DB_PATH', 'storage/database.sqlite')
            ];

            $db = new Database($dbConfig);
            
            // Initialize Schema
            (new \App\Database\Schema($db))->init();
            
            return $db;
        });

        // Bind Telegram Client
        $this->container->singleton(Client::class, function($c) {
            return new Client($c->get(Config::class));
        });
        
        // Bind Logger
        $this->container->singleton(\App\Services\Logger::class, function($c) {
            return new \App\Services\Logger($c->get(Config::class), $c->get(Client::class));
        });

        // Bind Plugin Manager
        $this->container->singleton(PluginManager::class, function($c) {
            return new PluginManager($c);
        });
        
        // Load Core Plugins
        $this->loadPlugins();
    }

    private function loadPlugins(): void {
        $pm = $this->container->get(PluginManager::class);
        
        // Register Core Module
        $pm->register(\App\Modules\CoreModule::class);
        $pm->register(\App\Modules\ModerationModule::class);
        $pm->register(\App\Modules\RuleModule::class);
        $pm->register(\App\Modules\AdminModule::class);
        $pm->register(\App\Modules\ReputationModule::class);
        $pm->register(\App\Modules\AnalyticsModule::class);
        $pm->register(\App\Modules\CaptchaModule::class);
        $pm->register(\App\Modules\AutomationModule::class);
        $pm->register(\App\Modules\IntegrationModule::class);
        $pm->register(\App\Modules\PaymentModule::class);
        
        // Boot all plugins
        $pm->boot();
    }

    public function run(): void {
        $input = file_get_contents('php://input');
        if (!$input) return;

        $update = json_decode($input, true);
        if (!$update) return;

        // Process Update
        $this->processUpdate($update);
    }

    private function processUpdate(array $update): void {
        $pm = $this->container->get(PluginManager::class);
        $pm->dispatch('update.received', $update);
    }

    public function getContainer(): Container {
        return $this->container;
    }
}
