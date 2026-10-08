<?php
$ch = curl_init('https://generativelanguage.googleapis.com');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_exec($ch);
echo 'HTTP: ' . curl_getinfo($ch, CURLINFO_HTTP_CODE) . PHP_EOL;
echo 'cURL error code: ' . curl_errno($ch) . PHP_EOL;
echo 'cURL error: ' . curl_error($ch) . PHP_EOL;
curl_close($ch);
