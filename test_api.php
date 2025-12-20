<?php
function testUrl($url) {
    echo "Testing $url ...\n";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'TelegramBot/1.0');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        echo "FAIL: Curl Error: $error\n";
    } else {
        echo "HTTP Code: $httpCode\n";
        echo "Response Length: " . strlen($response) . "\n";
        echo "Response Preview: " . substr($response, 0, 100) . "\n";
    }
    echo "--------------------------------------------------\n";
}

testUrl("https://geocoding-api.open-meteo.com/v1/search?name=London&count=1&language=en&format=json");
testUrl("https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=usd");
testUrl("https://wttr.in/London?format=j1");
