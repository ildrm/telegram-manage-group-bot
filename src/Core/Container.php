<?php
namespace App\Core;

class Container {
    private array $services = [];

    public function bind(string $key, callable $resolver): void {
        $this->services[$key] = $resolver;
    }

    public function get(string $key) {
        if (!isset($this->services[$key])) {
            throw new \Exception("Service not found: " . $key);
        }

        $resolver = $this->services[$key];
        return $resolver($this);
    }
    
    // Singleton support could be added here if needed, but simple factory for now is fine
    // actually for database we usually want singleton behavior
    private array $instances = [];
    
    public function singleton(string $key, callable $resolver): void {
        $this->services[$key] = function($c) use ($resolver, $key) {
            if (!isset($this->instances[$key])) {
                $this->instances[$key] = $resolver($c);
            }
            return $this->instances[$key];
        };
    }
}
