<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Bot;

// Handle cron requests for scheduled tasks
if (isset($_GET['cron'])) {
    try {
        $bot = new Bot();
        $container = $bot->getContainer();
        $config = $container->get(\App\Core\Config::class);

        // Authenticate cron requests. If no CRON_SECRET is configured, refuse to
        // run over HTTP (use a CLI invocation instead).
        $cronSecret = (string)$config->get('CRON_SECRET', '');
        $providedToken = (string)($_GET['token'] ?? '');
        if ($cronSecret === '' || !hash_equals($cronSecret, $providedToken)) {
            http_response_code(403);
            echo "Forbidden";
            exit;
        }

        // Run scheduled messages and cleanup tasks
        $db = $container->get(\App\Database\Database::class);
        
        // Send scheduled messages
        $stmt = $db->prepare("
            SELECT * FROM scheduled_messages 
            WHERE sent = 0 AND schedule_time <= ? 
            LIMIT 10
        ");
        $currentTime = date('H:i');
        $stmt->execute([$currentTime]);
        
        $client = $container->get(\App\Telegram\Client::class);
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            try {
                $client->sendMessage($row['group_id'], $row['message_text']);
                $updateStmt = $db->prepare("UPDATE scheduled_messages SET sent = 1 WHERE id = ?");
                $updateStmt->execute([$row['id']]);
            } catch (\Exception $e) {
                error_log("Failed to send scheduled message {$row['id']}: " . $e->getMessage());
            }
        }
        
        // Clean old CAPTCHA sessions (older than 5 minutes)
        $fiveMinutesAgo = time() - 300;
        $db->exec("DELETE FROM captcha_sessions WHERE created_at < $fiveMinutesAgo");
        
        // Clean old rate limits (older than 1 hour)
        $oneHourAgo = time() - 3600;
        $db->exec("DELETE FROM rate_limits WHERE timestamp < $oneHourAgo");
        
        echo "Cron completed successfully";
    } catch (Throwable $e) {
        error_log("Cron error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        http_response_code(500);
        echo "Cron error";
    }
    exit;
}

// Handle webhook updates
try {
    $bot = new Bot();

    // Validate Telegram's secret token (set via setWebhook's secret_token).
    // When WEBHOOK_SECRET is configured, reject any request whose header does
    // not match, preventing forged updates from arbitrary clients.
    $config = $bot->getContainer()->get(\App\Core\Config::class);
    $webhookSecret = (string)$config->get('WEBHOOK_SECRET', '');
    if ($webhookSecret !== '') {
        $headerToken = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
        if (!is_string($headerToken) || !hash_equals($webhookSecret, $headerToken)) {
            http_response_code(403);
            echo "Forbidden";
            exit;
        }
    }

    $bot->run();
    http_response_code(200);
} catch (Throwable $e) {
    // Log error to file
    $logFile = __DIR__ . '/../storage/logs/error.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $errorMsg = date('Y-m-d H:i:s') . ' [' . get_class($e) . '] ' . $e->getMessage() . "\n";
    $errorMsg .= "Trace: " . $e->getTraceAsString() . "\n\n";
    file_put_contents($logFile, $errorMsg, FILE_APPEND);
    
    // Still return 200 to Telegram to prevent retries for fatal errors
    http_response_code(200);
}
