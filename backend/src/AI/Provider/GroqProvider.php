<?php

namespace App\AI\Provider;

use App\AI\Interface\ChatProviderInterface;
use App\AI\Exception\ProviderException;
use OpenAI;
use Psr\Log\LoggerInterface;

/**
 * Groq Provider - Fast LLM inference with OpenAI-compatible API
 * https://console.groq.com/docs/
 */
class GroqProvider implements ChatProviderInterface
{
    private $client;

    public function __construct(
        private LoggerInterface $logger,
        private ?string $apiKey = null
    ) {
        if (!empty($apiKey)) {
            // Groq uses OpenAI-compatible client with custom base URL
            $this->client = OpenAI::factory()
                ->withApiKey($apiKey)
                ->withBaseUri('https://api.groq.com/openai/v1')
                ->make();
        }
    }

    public function getName(): string
    {
        return 'groq';
    }

    public function getCapabilities(): array
    {
        return ['chat'];
    }

    public function getDefaultModels(): array
    {
        return [];
    }

    public function getStatus(): array
    {
        if (!$this->client) {
            return [
                'healthy' => false,
                'error' => 'API key not configured',
            ];
        }

        return [
            'healthy' => true,
            'latency_ms' => 50,
            'error_rate' => 0.0,
            'active_connections' => 0,
        ];
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey) && $this->client !== null;
    }

    // ==================== CHAT ====================

    public function chat(array $messages, array $options = []): string
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'groq');
        }

        if (!$this->client) {
            throw ProviderException::missingApiKey('groq', 'GROQ_API_KEY');
        }

        try {
            $model = $options['model'];
            
            $this->logger->info('Groq chat request', [
                'model' => $model,
                'message_count' => count($messages)
            ]);

            $requestOptions = [
                'model' => $model,
                'messages' => $messages,
            ];

            if (isset($options['max_tokens'])) {
                $requestOptions['max_tokens'] = $options['max_tokens'];
            }

            if (isset($options['temperature'])) {
                $requestOptions['temperature'] = $options['temperature'];
            }

            $response = $this->client->chat()->create($requestOptions);

            return $response->choices[0]->message->content ?? '';
        } catch (\Exception $e) {
            $this->logger->error('Groq chat error', [
                'error' => $e->getMessage(),
                'model' => $options['model'] ?? 'unknown'
            ]);
            
            throw new ProviderException(
                'Groq chat error: ' . $e->getMessage(),
                'groq',
                null,
                0,
                $e
            );
        }
    }

    public function chatStream(array $messages, callable $callback, array $options = []): void
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'groq');
        }

        if (!$this->client) {
            throw ProviderException::missingApiKey('groq', 'GROQ_API_KEY');
        }

        try {
            $model = $options['model'];
            // Note: Qwen3 models send <think> tags directly in content, not via reasoning_format
            // reasoning_format is mainly for OpenAI o-series models
            
            $this->logger->info('🟢 Groq streaming chat START', [
                'model' => $model,
                'message_count' => count($messages)
            ]);

            $requestOptions = [
                'model' => $model,
                'messages' => $messages,
                'stream' => true,
            ];

            if (isset($options['max_tokens'])) {
                $requestOptions['max_tokens'] = $options['max_tokens'];
            }

            if (isset($options['temperature'])) {
                $requestOptions['temperature'] = $options['temperature'];
            }

            // Note: Qwen3 models automatically include <think> tags in content
            // We don't need to set reasoning_format for Groq
            
            $stream = $this->client->chat()->createStreamed($requestOptions);

            $chunkCount = 0;

            foreach ($stream as $response) {
                $chunkCount++;
                
                // Handle reasoning content (for models with structured reasoning like OpenAI o1)
                // @phpstan-ignore-next-line - Groq API response structure varies by model
                if (isset($response->choices[0]->delta->reasoning_content)) {
                    $reasoningContent = $response->choices[0]->delta->reasoning_content;
                    
                    $callback([
                        'type' => 'reasoning',
                        'content' => $reasoningContent
                    ]);
                }
                
                // Handle regular content (may include <think> tags for models like Qwen3)
                if (isset($response->choices[0]->delta->content)) {
                    $content = $response->choices[0]->delta->content;
                    
                    // Send as plain string (not structured) so <think> tags pass through
                    $callback($content);
                }
            }

            $this->logger->info('✅ Groq streaming COMPLETE', [
                'model' => $model,
                'chunks' => $chunkCount
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Groq streaming error', [
                'error' => $e->getMessage(),
                'model' => $options['model'] ?? 'unknown'
            ]);
            
            throw new ProviderException(
                'Groq streaming error: ' . $e->getMessage(),
                'groq',
                null,
                0,
                $e
            );
        }
    }
}

