<?php
namespace App\Core;

class Config {
    private array $settings = [];

    public function __construct() {
        $this->loadEnv();
    }

    private function loadEnv(): void {
        $envFile = __DIR__ . '/../../.env';
        if (!file_exists($envFile)) {
            // Fallback to example if no .env exists (for development start)
            $envFile = __DIR__ . '/../../.env.example';
        }

        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                
                list($name, $value) = explode('=', $line, 2);
                $this->settings[trim($name)] = trim($value);
            }
        }
    }

    public function get(string $key, $default = null) {
        return $this->settings[$key] ?? $default;
    }
}
