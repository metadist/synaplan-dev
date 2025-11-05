<?php

/**
 * Test script for Anthropic API
 * Run: php test-anthropic.php
 */

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\HttpClient\HttpClient;

$apiKey = getenv('ANTHROPIC_API_KEY');

if (empty($apiKey)) {
    die("ERROR: ANTHROPIC_API_KEY not set\n");
}

echo "Testing Anthropic API...\n";
echo "API Key: " . substr($apiKey, 0, 20) . "...\n\n";

$client = HttpClient::create();

$models = [
    'claude-3-5-sonnet-20241022',
    'claude-3-5-sonnet-latest',
    'claude-3-5-sonnet',
    'claude-3-opus-20240229',
    'claude-3-sonnet-20240229',
    'claude-3-haiku-20240307',
];

foreach ($models as $model) {
    echo "Testing model: $model\n";
    
    $requestBody = [
        'model' => $model,
        'max_tokens' => 50,
        'messages' => [
            [
                'role' => 'user',
                'content' => 'Hi'
            ]
        ]
    ];

    try {
        $response = $client->request('POST', 'https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ],
            'json' => $requestBody,
        ]);

        $statusCode = $response->getStatusCode();
        
        if ($statusCode === 200) {
            echo "  ✅ SUCCESS! Model works\n";
        } else {
            echo "  ❌ ERROR: Status $statusCode\n";
        }

    } catch (\Exception $e) {
        $errorMsg = $e->getMessage();
        if (method_exists($e, 'getResponse')) {
            try {
                $response = $e->getResponse();
                $errorData = $response->toArray(false);
                $errorMsg = $errorData['error']['message'] ?? $errorMsg;
            } catch (\Exception $parseError) {
                // Ignore
            }
        }
        echo "  ❌ ERROR: $errorMsg\n";
    }
    
    echo "\n";
}

