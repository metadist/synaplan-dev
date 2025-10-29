<?php

namespace App\AI\Provider;

use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\Exception\ProviderException;
use ArdaGnsrn\Ollama\Ollama;
use Psr\Log\LoggerInterface;

class OllamaProvider implements ChatProviderInterface, EmbeddingProviderInterface
{
    private $client;

    public function __construct(
        private LoggerInterface $logger,
        private string $baseUrl
    ) {
        // Set timeout to 5 minutes for slow CPU-based models
        ini_set('default_socket_timeout', 300);
        $this->client = Ollama::client($this->baseUrl);
    }

    public function getName(): string
    {
        return 'ollama';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'embedding'];
    }

    public function getDefaultModels(): array
    {
        return []; // Models come from DB (BMODELS), not provider
    }

    public function getStatus(): array
    {
        try {
            $start = microtime(true);
            $models = $this->client->models()->list();
            $latency = (microtime(true) - $start) * 1000;

            return [
                'healthy' => true,
                'latency_ms' => round($latency, 2),
                'error_rate' => 0.0,
                'active_connections' => 0,
                'models' => count($models->models ?? []),
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function isAvailable(): bool
    {
        try {
            $this->client->models()->list();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function chat(array $messages, array $options = []): string
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'ollama');
        }

        try {
            $model = $options['model'];
            
            $this->logger->info('Ollama chat request', [
                'model' => $model,
                'message_count' => count($messages)
            ]);

            // Build prompt from messages (Ollama Completions API style)
            $prompt = '';
            foreach ($messages as $message) {
                $role = $message['role'] ?? 'user';
                $content = $message['content'] ?? '';
                
                if ($role === 'system') {
                    $prompt .= $content . "\n\n";
                } elseif ($role === 'user') {
                    $prompt .= "User: " . $content . "\n";
                } elseif ($role === 'assistant') {
                    $prompt .= "Assistant: " . $content . "\n";
                }
            }

            // Use completions API (non-streaming)
            $response = $this->client->completions()->create([
                'model' => $model,
                'prompt' => $prompt,
            ]);

            return $response->response ?? '';
        } catch (\Exception $e) {
            $this->logger->error('Ollama chat error', [
                'error' => $e->getMessage(),
                'model' => $options['model'] ?? 'unknown'
            ]);
            
            // Check if error is about model not found
            $errorMsg = $e->getMessage();
            if (stripos($errorMsg, '404') !== false || 
                stripos($errorMsg, 'not found') !== false || 
                stripos($errorMsg, 'model') !== false) {
                throw ProviderException::noModelAvailable('chat', 'ollama', $model, $e);
            }
            
            throw new ProviderException(
                'Ollama chat error: ' . $e->getMessage(),
                'ollama'
            );
        }
    }

    public function chatStream(array $messages, callable $callback, array $options = []): void
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'ollama');
        }

        try {
            $model = $options['model'];
            $modelFeatures = $options['modelFeatures'] ?? [];
            $supportsReasoning = in_array('reasoning', $modelFeatures, true);
            
            // Check if model exists before attempting to use it
            $availableModels = $this->getAvailableModels();
            if (empty($availableModels)) {
                throw ProviderException::noModelAvailable('chat', 'ollama', $model);
            }
            
            $modelExists = false;
            foreach ($availableModels as $availableModel) {
                if (stripos($availableModel, $model) !== false || stripos($model, $availableModel) !== false) {
                    $modelExists = true;
                    break;
                }
            }
            
            if (!$modelExists) {
                throw ProviderException::noModelAvailable('chat', 'ollama', $model);
            }
            
            $this->logger->info('🔵 Ollama streaming chat START', [
                'model' => $model,
                'message_count' => count($messages),
                'supportsReasoning' => $supportsReasoning
            ]);

            // Build prompt from messages (Ollama Completions API style)
            $prompt = '';
            foreach ($messages as $message) {
                $role = $message['role'] ?? 'user';
                $content = $message['content'] ?? '';
                
                if ($role === 'system') {
                    $prompt .= $content . "\n\n";
                } elseif ($role === 'user') {
                    $prompt .= "User: " . $content . "\n";
                } elseif ($role === 'assistant') {
                    $prompt .= "Assistant: " . $content . "\n";
                }
            }
            
            $this->logger->info('🟡 Ollama: Prompt built', ['length' => strlen($prompt)]);

            // Use completions API with streaming
            $stream = $this->client->completions()->createStreamed([
                'model' => $model,
                'prompt' => $prompt,
            ]);
            
            $this->logger->info('🟡 Ollama: Stream created, iterating...');
            
            $chunkCount = 0;
            $fullResponse = '';
            $inThinkBlock = false;
            $thinkBuffer = '';
            
            foreach ($stream as $completion) {
                // Extract response content
                $textChunk = $completion->response ?? '';
                
                if (!empty($textChunk)) {
                    $fullResponse .= $textChunk;
                    
                    // Parse <think> tags ONLY if model supports reasoning (from DB)
                    if ($supportsReasoning) {
                        // Check for <think> opening tag
                        if (preg_match('/<think>/', $textChunk)) {
                            $inThinkBlock = true;
                            $thinkBuffer = '';
                            // Extract content after <think>
                            $parts = preg_split('/<think>/', $textChunk, 2);
                            if (count($parts) > 1) {
                                $thinkBuffer .= $parts[1];
                            }
                            $this->logger->info('🧠 Ollama: <think> tag detected, starting reasoning buffer');
                            continue;
                        }
                        
                        // Check for </think> closing tag
                        if ($inThinkBlock && preg_match('/<\/think>/', $textChunk)) {
                            $parts = preg_split('/<\/think>/', $textChunk, 2);
                            $thinkBuffer .= $parts[0];
                            
                            // Send accumulated thinking as reasoning chunk
                            if (!empty($thinkBuffer)) {
                                $this->logger->info('🧠 Ollama: Sending reasoning chunk', [
                                    'length' => strlen($thinkBuffer),
                                    'preview' => substr($thinkBuffer, 0, 100)
                                ]);
                                
                                $callback([
                                    'type' => 'reasoning',
                                    'content' => $thinkBuffer
                                ]);
                            }
                            
                            $inThinkBlock = false;
                            $thinkBuffer = '';
                            
                            // Send remaining content after </think> as regular text
                            if (isset($parts[1]) && !empty($parts[1])) {
                                $callback($parts[1]);
                            }
                            continue;
                        }
                        
                        // If inside think block, accumulate
                        if ($inThinkBlock) {
                            $thinkBuffer .= $textChunk;
                            continue;
                        }
                    }
                    
                    // Regular content (not in <think> block or reasoning not supported)
                    $callback($textChunk);
                    $chunkCount++;
                    
                    if ($chunkCount === 1) {
                        $this->logger->info('🟢 Ollama: First chunk sent!', [
                            'length' => strlen($textChunk),
                            'preview' => substr($textChunk, 0, 50)
                        ]);
                    }
                }
                
                // Check if done
                if (isset($completion->done) && $completion->done) {
                    $this->logger->info('🔵 Ollama: Stream done signal received');
                    break;
                }
            }
            
            $this->logger->info('🔵 Ollama: Streaming complete', [
                'chunks_sent' => $chunkCount,
                'total_length' => strlen($fullResponse)
            ]);
        } catch (ProviderException $e) {
            // Re-throw ProviderException as-is (with our friendly message)
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('🔴 Ollama streaming error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Check if error is about model not found (404, "not found", etc.)
            $errorMsg = $e->getMessage();
            if (stripos($errorMsg, '404') !== false || 
                stripos($errorMsg, 'not found') !== false || 
                stripos($errorMsg, 'model') !== false) {
                throw ProviderException::noModelAvailable('chat', 'ollama', $model, $e);
            }
            
            throw new ProviderException(
                'Ollama streaming error: ' . $e->getMessage(),
                'ollama',
                null,
                0,
                $e
            );
        }
    }
    
    /**
     * Get list of available models from Ollama
     */
    private function getAvailableModels(): array
    {
        try {
            $models = $this->client->models()->list();
            $modelNames = [];
            foreach (($models->models ?? []) as $model) {
                $modelNames[] = $model->model ?? $model->name ?? '';
            }
            return array_filter($modelNames);
        } catch (\Exception $e) {
            $this->logger->error('Failed to list Ollama models', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function embed(string $text, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Embedding model must be specified in options', 'ollama');
        }

        try {
            $response = $this->client->embed()->create([
                'model' => $options['model'],
                'input' => [$text],
            ]);

            $arrRes = method_exists($response, 'toArray') ? $response->toArray() : (array) $response;
            return $arrRes['embeddings'][0] ?? [];
        } catch (\Exception $e) {
            throw new ProviderException(
                'Ollama embedding error: ' . $e->getMessage(),
                'ollama'
            );
        }
    }

    public function embedBatch(array $texts, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Embedding model must be specified in options', 'ollama');
        }

        return array_map(fn($text) => $this->embed($text, $options), $texts);
    }

    public function getDimensions(string $model): int
    {
        return match(true) {
            str_contains($model, 'bge-m3') => 1024,
            str_contains($model, 'nomic-embed-text') => 768,
            str_contains($model, 'mxbai-embed-large') => 1024,
            str_contains($model, 'all-minilm') => 384,
            default => 1024 // Default to 1024 for Ollama models
        };
    }
}

