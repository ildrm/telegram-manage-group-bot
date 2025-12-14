<?php
namespace App\Modules;

use App\Interfaces\PluginInterface;
use App\Core\Container;
use App\Services\RuleEvaluator;
use App\Telegram\Client;

class RuleModule implements PluginInterface {
    private array $rules = [];

    public function register(Container $container): void {
        $container->singleton(RuleEvaluator::class, function() {
            return new RuleEvaluator();
        });
        
        $this->loadRules();
    }

    private function loadRules(): void {
        // Load from JSON file for now
        $path = __DIR__ . '/../../storage/rules.json';
        if (file_exists($path)) {
            $this->rules = json_decode(file_get_contents($path), true) ?? [];
        } else {
            // Default demo rule
            $this->rules = [
                [
                    'name' => 'No PDFs',
                    'condition' => ['message.document.mime_type' => 'application/pdf'],
                    'action' => 'delete'
                ]
            ];
            // Write it
            if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
            file_put_contents($path, json_encode($this->rules, JSON_PRETTY_PRINT));
        }
    }

    public function boot(Container $container): void {
    }

    public function getListeners(): array {
        return [
            'update.received' => 'handleUpdate'
        ];
    }

    public function handleUpdate(array $update, Container $container): void {
        if (!isset($update['message'])) return;
        
        $evaluator = $container->get(RuleEvaluator::class);
        $client = $container->get(Client::class);
        $chatId = $update['message']['chat']['id'];
        
        foreach ($this->rules as $rule) {
            if ($evaluator->evaluate($rule, $update)) {
                // Execute Action
                if ($rule['action'] === 'delete') {
                    // $client->deleteMessage...
                    $client->sendMessage($chatId, "⚠️ Rule triggered: " . $rule['name'] . " (Action: delete)");
                } elseif ($rule['action'] === 'warn') {
                    $client->sendMessage($chatId, "⚠️ Warning: " . $rule['name']);
                }
            }
        }
    }
}
