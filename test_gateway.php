<?php
/**
 * Test if PHP can reach gateway
 */

// Set your actual paths
define('QA_INCLUDE_DIR', '/path/to/your/king-include/');
define('QA_VERSION', '1.0');

require_once QA_INCLUDE_DIR . 'king-app/gateway.php';

echo "<h1>Gateway Test</h1>\n";
echo "<pre>\n";

// Test 1: Check if gateway is enabled
echo "=" . str_repeat("=", 59) . "\n";
echo "TEST 1: Gateway Configuration\n";
echo "=" . str_repeat("=", 59) . "\n";

$enabled = qa_opt('gateway_enabled');
$url = qa_opt('gateway_url');
$token = qa_opt('gateway_token');

echo "Enabled: " . ($enabled ? 'YES' : 'NO') . "\n";
echo "URL: " . ($url ?: 'NOT SET') . "\n";
echo "Token: " . ($token ? 'SET (hidden)' : 'NOT SET') . "\n";
echo "Gateway Class Exists: " . (class_exists('Ebonix_Gateway') ? 'YES' : 'NO') . "\n";
echo "Gateway Enabled Method: " . (Ebonix_Gateway::enabled() ? 'YES' : 'NO') . "\n";

// Test 2: Try to generate image
echo "\n" . str_repeat("=", 60) . "\n";
echo "TEST 2: Image Generation\n";
echo "=" . str_repeat("=", 59) . "\n";

if (!Ebonix_Gateway::enabled()) {
    echo "❌ SKIPPED: Gateway not enabled\n";
    echo "\nTo enable:\n";
    echo "1. Go to Admin Panel > Gateway Settings\n";
    echo "2. Enable gateway\n";
    echo "3. Set URL: http://localhost:8000\n";
    echo "4. Set Token: ebonix_secret_12345\n";
} else {
    echo "⏳ Attempting to generate image...\n\n";
    
    $result = Ebonix_Gateway::generate_image(
        "create image of beautiful girl",
        "banana",
        "1024x1024",
        "",
        ""
    );
    
    if (!empty($result['success'])) {
        echo "✅ SUCCESS!\n";
        echo "Model used: " . ($result['model_used'] ?? 'unknown') . "\n";
        echo "Enhanced prompt: " . ($result['enhanced_prompt'] ?? 'none') . "\n";
        echo "Image URL length: " . strlen($result['image_url']) . " chars\n";
    } else {
        echo "❌ FAILED!\n";
        echo "Error: " . ($result['error'] ?? 'Unknown error') . "\n";
    }
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "TEST COMPLETE\n";
echo "=" . str_repeat("=", 59) . "\n";

echo "</pre>\n";