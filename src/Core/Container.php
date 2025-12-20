<?php
namespace App\Core;

class Container {
    private array $services = [];
    private array $instances = [];

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
    
    public function singleton(string $key, callable $resolver): void {
        $container = $this;
        $this->services[$key] = function($c) use ($resolver, $key, $container) {
            if (!isset($container->instances[$key])) {
                $container->instances[$key] = $resolver($c);
            }
            return $container->instances[$key];
        };
    }
    
    public function has(string $key): bool {
        return isset($this->services[$key]);
    }
}
