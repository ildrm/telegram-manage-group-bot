<?php
namespace App\Core;

use App\Interfaces\PluginInterface;

class PluginManager {
    private Container $container;
    private array $plugins = [];
    private array $listeners = [];

    public function __construct(Container $container) {
        $this->container = $container;
    }

    public function register(string $pluginClass): void {
        $plugin = new $pluginClass();
        if (!$plugin instanceof PluginInterface) {
            throw new \Exception("Plugin must implement PluginInterface");
        }
        
        $plugin->register($this->container);
        $this->plugins[] = $plugin;
        
        // Register listeners
        foreach ($plugin->getListeners() as $event => $method) {
            $this->listeners[$event][] = [$plugin, $method];
        }
    }

    public function boot(): void {
        foreach ($this->plugins as $plugin) {
            $plugin->boot($this->container);
        }
    }

    public function dispatch(string $event, $payload = null): void {
        if (!isset($this->listeners[$event])) {
            return;
        }

        foreach ($this->listeners[$event] as $callback) {
            call_user_func($callback, $payload, $this->container);
        }
    }
}
