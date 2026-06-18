<?php
/**
 * CLI helper to register (or delete) the Telegram webhook with a secret token.
 *
 * Usage:
 *   php bin/set_webhook.php set https://yourdomain.com/public/webhook.php
 *   php bin/set_webhook.php delete
 *   php bin/set_webhook.php info
 *
 * Reads BOT_TOKEN and WEBHOOK_SECRET from .env. When WEBHOOK_SECRET is set, it is
 * passed as Telegram's `secret_token`, so public/webhook.php can reject any
 * request that doesn't echo it back in the X-Telegram-Bot-Api-Secret-Token header.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Telegram\Client;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

$config = new Config();
$client = new Client($config);

$action = $argv[1] ?? 'info';

switch ($action) {
    case 'set':
        $url = $argv[2] ?? '';
        if ($url === '') {
            fwrite(STDERR, "Error: provide the webhook URL.\nExample: php bin/set_webhook.php set https://example.com/public/webhook.php\n");
            exit(1);
        }
        $params = [
            'url' => $url,
            'allowed_updates' => ['message', 'callback_query', 'my_chat_member', 'chat_member'],
            'drop_pending_updates' => false,
        ];
        $secret = (string)$config->get('WEBHOOK_SECRET', '');
        if ($secret !== '') {
            $params['secret_token'] = $secret;
            echo "Using WEBHOOK_SECRET from .env as secret_token.\n";
        } else {
            fwrite(STDERR, "WARNING: WEBHOOK_SECRET is empty — the webhook will accept unauthenticated requests.\n");
        }
        $res = $client->request('setWebhook', $params);
        break;

    case 'delete':
        $res = $client->request('deleteWebhook', ['drop_pending_updates' => false]);
        break;

    case 'info':
    default:
        $res = $client->request('getWebhookInfo');
        break;
}

echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit(($res['ok'] ?? false) ? 0 : 1);
