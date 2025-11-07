<?php

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\HttpClient\HttpClient;

$apiKey = getenv('GROQ_API_KEY');

if (empty($apiKey)) {
    die("ERROR: GROQ_API_KEY not set\n");
}

echo "Testing Qwen3-32B Reasoning with <think> tags\n\n";

$client = HttpClient::create();

// Test 1: Simple greeting (NO thinking expected)
echo "=== Test 1: Simple greeting (NO thinking) ===\n";
testQwen('Hi', $client, $apiKey);

echo "\n\n";

// Test 2: Math problem (SHOULD have thinking)
echo "=== Test 2: Math problem (SHOULD have thinking) ===\n";
testQwen('What is 25 * 17? Think step by step.', $client, $apiKey);

echo "\n\n";

// Test 3: Logic problem (SHOULD have thinking)
echo "=== Test 3: Logic problem (SHOULD have thinking) ===\n";
testQwen('If all roses are flowers and some flowers fade quickly, can we conclude that some roses fade quickly? Think carefully.', $client, $apiKey);

function testQwen($message, $client, $apiKey) {
    $requestBody = [
        'model' => 'qwen/qwen3-32b',
        'messages' => [['role' => 'user', 'content' => $message]],
        'max_tokens' => 500,
    ];

    try {
        $response = $client->request('POST', 'https://api.groq.com/openai/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $requestBody,
            'timeout' => 30,
        ]);

        $data = $response->toArray();
        $content = $data['choices'][0]['message']['content'] ?? '';
        
        // Check for <think> tags
        $hasThinkTags = strpos($content, '<think>') !== false;
        
        echo "Message: \"$message\"\n";
        echo "Has <think> tags: " . ($hasThinkTags ? '✅ YES' : '❌ NO') . "\n";
        
        if ($hasThinkTags) {
            // Extract thinking content
            if (preg_match('/<think>(.*?)<\/think>/s', $content, $matches)) {
                $thinking = $matches[1];
                echo "Thinking preview: " . substr(trim($thinking), 0, 100) . "...\n";
            }
        }
        
        echo "Response preview: " . substr($content, 0, 150) . "...\n";

    } catch (\Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}

