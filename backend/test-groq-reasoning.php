<?php

/**
 * Test Groq Reasoning API - Check actual response format
 */

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\HttpClient\HttpClient;

$apiKey = getenv('GROQ_API_KEY');

if (empty($apiKey)) {
    die("ERROR: GROQ_API_KEY not set\n");
}

echo "Testing Groq Reasoning API with different formats...\n\n";

$client = HttpClient::create();

$model = 'openai/gpt-oss-20b';
$message = 'What is 2+2? Think step by step.';

// Test 1: With reasoning_format = 'parsed'
echo "=== Test 1: reasoning_format = 'parsed' ===\n";
$requestBody = [
    'model' => $model,
    'messages' => [['role' => 'user', 'content' => $message]],
    'reasoning_format' => 'parsed',
    'stream' => true,
];

echo "Request: " . json_encode($requestBody, JSON_PRETTY_PRINT) . "\n\n";

try {
    $response = $client->request('POST', 'https://api.groq.com/openai/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ],
        'json' => $requestBody,
        'timeout' => 30,
    ]);

    echo "Response:\n";
    foreach ($client->stream($response) as $chunk) {
        if ($chunk->isLast()) {
            break;
        }
        
        $content = $chunk->getContent();
        
        // Parse SSE format
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            if (strpos($line, 'data: ') === 0) {
                $jsonData = substr($line, 6);
                if ($jsonData === '[DONE]') {
                    echo "\n[DONE]\n";
                    continue;
                }
                
                $data = json_decode($jsonData, true);
                if (isset($data['choices'][0]['delta'])) {
                    $delta = $data['choices'][0]['delta'];
                    
                    if (isset($delta['reasoning_content'])) {
                        echo "REASONING: " . $delta['reasoning_content'] . "\n";
                    }
                    
                    if (isset($delta['content'])) {
                        echo "CONTENT: " . $delta['content'] . "\n";
                    }
                }
            }
        }
    }
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n\n";

// Test 2: With reasoning_format = 'raw'
echo "=== Test 2: reasoning_format = 'raw' ===\n";
$requestBody['reasoning_format'] = 'raw';

try {
    $response = $client->request('POST', 'https://api.groq.com/openai/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ],
        'json' => $requestBody,
        'timeout' => 30,
    ]);

    echo "Response:\n";
    $fullContent = '';
    foreach ($client->stream($response) as $chunk) {
        if ($chunk->isLast()) {
            break;
        }
        
        $content = $chunk->getContent();
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            if (strpos($line, 'data: ') === 0) {
                $jsonData = substr($line, 6);
                if ($jsonData === '[DONE]') {
                    continue;
                }
                
                $data = json_decode($jsonData, true);
                if (isset($data['choices'][0]['delta']['content'])) {
                    $fullContent .= $data['choices'][0]['delta']['content'];
                }
            }
        }
    }
    
    echo $fullContent . "\n";
    
    // Check for <think> tags
    if (strpos($fullContent, '<think>') !== false) {
        echo "\n✅ Found <think> tags in raw format!\n";
    } else {
        echo "\n❌ No <think> tags found\n";
    }
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n\n";

// Test 3: WITHOUT reasoning_format (default)
echo "=== Test 3: No reasoning_format (default) ===\n";
unset($requestBody['reasoning_format']);

try {
    $response = $client->request('POST', 'https://api.groq.com/openai/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ],
        'json' => $requestBody,
        'timeout' => 30,
    ]);

    echo "Response:\n";
    $fullContent = '';
    foreach ($client->stream($response) as $chunk) {
        if ($chunk->isLast()) {
            break;
        }
        
        $content = $chunk->getContent();
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            if (strpos($line, 'data: ') === 0) {
                $jsonData = substr($line, 6);
                if ($jsonData === '[DONE]') {
                    continue;
                }
                
                $data = json_decode($jsonData, true);
                if (isset($data['choices'][0]['delta']['content'])) {
                    $fullContent .= $data['choices'][0]['delta']['content'];
                }
            }
        }
    }
    
    echo $fullContent . "\n";
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

