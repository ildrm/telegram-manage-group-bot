<?php
namespace App\Services;

use App\Core\Config;
use App\Telegram\Client;

class Logger {
    private string $logPath;
    private $logChannelId;
    private Client $client;

    public function __construct(Config $config, Client $client) {
        $this->logPath = dirname(dirname(__DIR__)) . '/storage/logs/app.log';
        $this->logChannelId = $config->get('LOG_CHANNEL_ID');
        $this->client = $client;
        
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
    }

    public function info(string $message, array $context = []): void {
        $this->log('INFO', $message, $context);
    }

    public function error(string $message, array $context = []): void {
        $this->log('ERROR', $message, $context);
    }
    
    public function warning(string $message, array $context = []): void {
        $this->log('WARNING', $message, $context);
    }

    private function log(string $level, string $message, array $context): void {
        $timestamp = date('Y-m-d H:i:s');
        $contextString = !empty($context) ? json_encode($context) : '';
        $line = "[$timestamp] $level: $message $contextString" . PHP_EOL;
        
        file_put_contents($this->logPath, $line, FILE_APPEND);
        
        // Log to Telegram Channel if critical/error
        if ($level === 'ERROR' && $this->logChannelId) {
            $this->client->sendMessage($this->logChannelId, "🚨 <b>Error Log</b>\n\n$message\n<pre>$contextString</pre>");
        }
    }
}
