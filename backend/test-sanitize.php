<?php

// Test sanitizeConfig logic

$defaults = [
    'position' => 'bottom-right',
    'primaryColor' => '#007bff',
    'iconColor' => '#ffffff',
    'defaultTheme' => 'light',
    'autoOpen' => false,
    'autoMessage' => 'Hello! How can I help you today?',
    'messageLimit' => 50,
    'maxFileSize' => 10,
    'allowedDomains' => []
];

$config = [
    'position' => 'bottom-right',
    'primaryColor' => '#007bff',
    'iconColor' => '#ffffff',
    'defaultTheme' => 'light',
    'autoOpen' => false,
    'autoMessage' => 'Hello! How can I help you today?',
    'messageLimit' => 50,
    'maxFileSize' => 10,
    'allowedDomains' => ['test.com', 'example.com']
];

echo "=== TESTING OLD METHOD (array_merge) ===\n";
$result1 = array_merge($defaults, $config);
echo "Result: " . json_encode($result1, JSON_PRETTY_PRINT) . "\n\n";

echo "=== TESTING NEW METHOD (array_key_exists loop) ===\n";
$config2 = $config;
foreach ($defaults as $key => $defaultValue) {
    if (!array_key_exists($key, $config2)) {
        $config2[$key] = $defaultValue;
    }
}
echo "Result: " . json_encode($config2, JSON_PRETTY_PRINT) . "\n\n";

echo "=== TESTING WITH MISSING KEY ===\n";
$config3 = [
    'position' => 'bottom-left',
    'allowedDomains' => ['only-this.com']
];
echo "Input: " . json_encode($config3, JSON_PRETTY_PRINT) . "\n";

foreach ($defaults as $key => $defaultValue) {
    if (!array_key_exists($key, $config3)) {
        $config3[$key] = $defaultValue;
    }
}
echo "Result: " . json_encode($config3, JSON_PRETTY_PRINT) . "\n";

