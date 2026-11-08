<?php
declare(strict_types=1);
[$file, $host, $method, $path, $body] = $argv;
$ch = curl_init('http://'.$host.':9501'.$path);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30,
]);
if ($method === 'POST') {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}
$raw = curl_exec($ch);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
echo json_encode([
    'status' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
    'headers' => substr($raw, 0, $headerSize),
    'body' => json_decode(substr($raw, $headerSize), true),
], JSON_THROW_ON_ERROR);
