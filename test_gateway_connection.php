<?php
// Quick test - run from command line: php test_gateway_connection.php

$gateway_url = "https://ebonix.ai/";
$token = "ebonix_secret_12345";

echo "Testing gateway connection...\n\n";

// Test 1: Health check
echo "1. Health check: ";
$ch = curl_init($gateway_url . '/health');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code == 200) {
    echo "✅ OK\n";
    echo "   Response: $response\n\n";
} else {
    echo "❌ FAILED (HTTP $code)\n\n";
    exit(1);
}

// Test 2: Image generation
echo "2. Image generation test: ";

$payload = json_encode([
    'type' => 'image',
    'prompt' => 'beautiful girl',
    'model' => 'banana',
    'size' => '1024x1024',
    'representation_rules' => [
        'skin_tone' => 'diverse',
        'hair_texture' => 'diverse',
        'prevent_whitewashing' => true,
    ],
]);

$ch = curl_init($gateway_url . '/generate');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $token,
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 180);

echo "⏳ Generating (may take 30-60 seconds)...\n";
$response = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "   Response code: $code\n";

if ($code == 200) {
    $data = json_decode($response, true);
    if ($data['success']) {
        echo "   ✅ SUCCESS!\n";
        echo "   Model used: " . $data['model_used'] . "\n";
        echo "   Enhanced prompt: " . $data['enhanced_prompt'] . "\n";
        echo "   Image size: " . strlen($data['image_url']) . " chars\n";
    } else {
        echo "   ❌ FAILED: " . $data['error'] . "\n";
    }
} else {
    echo "   ❌ HTTP ERROR $code\n";
    echo "   Response: " . substr($response, 0, 500) . "\n";
}