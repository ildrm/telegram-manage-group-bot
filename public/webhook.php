<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Bot;

try {
    $bot = new Bot();
    $bot->run();
} catch (Throwable $e) {
    // Log error to file
    file_put_contents(__DIR__ . '/../storage/logs/error.log', date('Y-m-d H:i:s') . ' ' . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
}
