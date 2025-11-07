<?php

/**
 * Test script for Groq API - Testing DeepSeek R1 models
 */

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\HttpClient\HttpClient;

$apiKey = getenv('GROQ_API_KEY');

if (empty($apiKey)) {
    die("ERROR: GROQ_API_KEY not set\n");
}

echo "Testing Groq API for DeepSeek R1 models...\n";
echo "API Key: " . substr($apiKey, 0, 20) . "...\n\n";

$client = HttpClient::create();

// Test all Groq models from BMODELS.sql + potential new ones
$models = [
    // From BMODELS.sql
    'llama-3.3-70b-versatile',
    'meta-llama/llama-4-scout-17b-16e-instruct',
    'meta-llama/llama-4-maverick-17b-128e-instruct',
    'deepseek-r1-distill-llama-70b',
    'openai/gpt-oss-20b',
    'openai/gpt-oss-120b',
    'whisper-large-v3',
    
    // Potential reasoning models
    'deepseek-r1-distill-qwen-32b',
];

foreach ($models as $model) {
    echo "Testing model: $model\n";
    
    $requestBody = [
        'model' => $model,
        'messages' => [
            [
                'role' => 'user',
                'content' => 'Hi'
            ]
        ],
        'max_tokens' => 50
    ];

    try {
        $response = $client->request('POST', 'https://api.groq.com/openai/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $requestBody,
            'timeout' => 10,
        ]);

        $statusCode = $response->getStatusCode();
        
        if ($statusCode === 200) {
            $data = $response->toArray();
            $content = $data['choices'][0]['message']['content'] ?? '';
            echo "  ✅ SUCCESS! Response: " . substr($content, 0, 50) . "\n";
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

