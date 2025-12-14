<?php
namespace App\Interfaces;

use App\Core\Container;

interface PluginInterface {
    public function register(Container $container): void;
    public function boot(Container $container): void;
    // Helper to get event listeners
    public function getListeners(): array; 
    // Format: ['event_name' => 'methodName']
}
