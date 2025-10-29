<?php

namespace App\AI\Provider;

use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\ImageGenerationProviderInterface;
use App\AI\Interface\VideoGenerationProviderInterface;
use App\AI\Interface\VisionProviderInterface;
use App\AI\Interface\TextToSpeechProviderInterface;
use App\AI\Exception\ProviderException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google AI Provider
 * 
 * Supports:
 * - Gemini 2.0 Flash, Gemini 2.5 Pro (Chat, Vision)
 * - Imagen 3.0, Gemini 2.5 Flash Image (Image Generation)
 * - Veo 2.0 (Video Generation)
 * - Text-to-Speech with Gemini
 */
class GoogleProvider implements 
    ChatProviderInterface,
    ImageGenerationProviderInterface,
    VideoGenerationProviderInterface,
    VisionProviderInterface,
    TextToSpeechProviderInterface
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';
    private const VERTEX_BASE = 'https://{region}-aiplatform.googleapis.com/v1';

    public function __construct(
        private LoggerInterface $logger,
        private HttpClientInterface $httpClient,
        private ?string $apiKey = null,
        private ?string $projectId = null,
        private string $region = 'us-central1',
        private string $uploadDir = '/var/www/html/public/uploads'
    ) {
        // Ensure projectId is null if empty string
        if (empty($this->projectId)) {
            $this->projectId = null;
        }
    }

    public function getName(): string
    {
        return 'google';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'embedding', 'vision', 'image_generation', 'video_generation', 'text_to_speech'];
    }

    public function getDefaultModels(): array
    {
        return [];
    }

    public function getStatus(): array
    {
        if (!$this->apiKey) {
            return [
                'healthy' => false,
                'error' => 'API key not configured',
            ];
        }

        return [
            'healthy' => true,
            'latency_ms' => 100,
            'error_rate' => 0.0,
            'active_connections' => 0,
        ];
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }

    // ==================== CHAT ====================

    public function chat(array $messages, array $options = []): string
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'google');
        }

        if (!$this->apiKey) {
            throw ProviderException::missingApiKey('google', 'GOOGLE_GEMINI_API_KEY');
        }

        try {
            $model = $options['model'];
            $contents = $this->convertMessagesToGeminiFormat($messages);
            
            $url = self::API_BASE . "/models/{$model}:generateContent";

            $payload = [
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => $options['temperature'] ?? 0.7,
                    'topP' => $options['top_p'] ?? 0.95,
                    'topK' => $options['top_k'] ?? 40,
                    'maxOutputTokens' => $options['max_tokens'] ?? 2048,
                ],
            ];

            $this->logger->info('Google: Generating chat completion', [
                'model' => $model,
                'message_count' => count($messages)
            ]);

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ],
                'json' => $payload,
                'timeout' => 60,
            ]);

            $data = $response->toArray();
            
            return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        } catch (\Exception $e) {
            throw new ProviderException(
                'Google chat error: ' . $e->getMessage(),
                'google'
            );
        }
    }

    public function chatStream(array $messages, callable $callback, array $options = []): void
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'google');
        }

        if (!$this->apiKey) {
            throw ProviderException::missingApiKey('google', 'GOOGLE_GEMINI_API_KEY');
        }

        try {
            $model = $options['model'];
            $contents = $this->convertMessagesToGeminiFormat($messages);
            
            $url = self::API_BASE . "/models/{$model}:streamGenerateContent?alt=sse";

            $payload = [
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => $options['temperature'] ?? 0.7,
                    'topP' => $options['top_p'] ?? 0.95,
                    'topK' => $options['top_k'] ?? 40,
                    'maxOutputTokens' => $options['max_tokens'] ?? 2048,
                ],
            ];

            $this->logger->info('Google: Streaming chat completion', [
                'model' => $model,
                'message_count' => count($messages)
            ]);

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ],
                'json' => $payload,
                'timeout' => 120,
            ]);

            foreach ($this->httpClient->stream($response) as $chunk) {
                if ($chunk->isLast()) {
                    break;
                }
                
                $content = $chunk->getContent();
                
                // Parse SSE format: data: {...}
                if (str_starts_with($content, 'data: ')) {
                    $jsonData = substr($content, 6);
                    $data = json_decode($jsonData, true);
                    
                    if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                        $callback($data['candidates'][0]['content']['parts'][0]['text']);
                    }
                }
            }
        } catch (\Exception $e) {
            throw new ProviderException(
                'Google streaming error: ' . $e->getMessage(),
                'google'
            );
        }
    }

    // ==================== IMAGE GENERATION ====================

    public function generateImage(string $prompt, array $options = []): array
    {
        $model = $options['model'] ?? 'imagen-3.0-generate-002';
        
        if (!$this->apiKey) {
            throw ProviderException::missingApiKey('google', 'GOOGLE_GEMINI_API_KEY');
        }

        try {
            $this->logger->info('Google: Generating image', [
                'model' => $model,
                'prompt_length' => strlen($prompt)
            ]);

            // Imagen uses Vertex AI - requires project ID
            if (!$this->projectId) {
                throw new ProviderException('Google project ID required for image generation', 'google');
            }

            $url = str_replace('{region}', $this->region, self::VERTEX_BASE) 
                . "/projects/{$this->projectId}/locations/{$this->region}"
                . "/publishers/google/models/{$model}:predict";

            $payload = [
                'instances' => [
                    [
                        'prompt' => $prompt,
                    ]
                ],
                'parameters' => [
                    'sampleCount' => $options['n'] ?? 1,
                    'aspectRatio' => $options['aspect_ratio'] ?? '1:1',
                    'negativePrompt' => $options['negative_prompt'] ?? '',
                    'personGeneration' => 'allow_all',
                ],
            ];

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
                'json' => $payload,
                'timeout' => 60,
            ]);

            $data = $response->toArray();
            
            $images = [];
            foreach ($data['predictions'] ?? [] as $prediction) {
                // Imagen returns base64-encoded images
                if (isset($prediction['bytesBase64Encoded'])) {
                    $images[] = [
                        'url' => 'data:image/png;base64,' . $prediction['bytesBase64Encoded'],
                        'revised_prompt' => $prompt,
                    ];
                }
            }

            return $images;
        } catch (\Exception $e) {
            throw new ProviderException(
                'Google image generation error: ' . $e->getMessage(),
                'google'
            );
        }
    }

    public function createVariations(string $imageUrl, int $count = 1): array
    {
        throw new ProviderException('Image variations not supported by Google Imagen', 'google');
    }

    // ==================== VIDEO GENERATION ====================

    public function generateVideo(string $prompt, array $options = []): array
    {
        $model = $options['model'] ?? 'veo-3.1-generate-preview';
        
        if (!$this->apiKey) {
            throw ProviderException::missingApiKey('google', 'GOOGLE_GEMINI_API_KEY');
        }

        try {
            $this->logger->info('Google Veo: Starting video generation', [
                'model' => $model,
                'prompt_length' => strlen($prompt)
            ]);

            // Use Gemini API (not Vertex AI!) - predictLongRunning endpoint for async operation
            $url = self::API_BASE . "/models/{$model}:predictLongRunning";
            
            $payload = [
                'instances' => [
                    [
                        'prompt' => $prompt,
                    ]
                ]
            ];

            $this->logger->info('Google Veo: Sending video generation request', [
                'url' => $url,
                'model' => $model
            ]);

            // Start the long-running operation
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey, // Use x-goog-api-key header, NOT Authorization
                ],
                'json' => $payload,
                'timeout' => 30,
            ]);

            // Check status code BEFORE calling toArray()
            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $errorBody = $response->getContent(false); // false = don't throw on error
                $this->logger->error('Google Veo: API returned error', [
                    'status_code' => $statusCode,
                    'error_body' => $errorBody,
                    'url' => $url,
                    'payload' => json_encode($payload)
                ]);
                throw new \Exception("Google Veo API error (HTTP $statusCode): $errorBody");
            }

            $data = $response->toArray();
            
            $this->logger->info('Google Veo: Response received', [
                'response' => json_encode($data)
            ]);
            
            // Get the operation name
            $operationName = $data['name'] ?? null;
            if (!$operationName) {
                throw new \Exception('No operation name returned from Google Veo');
            }

            $this->logger->info('Google Veo: Operation started', [
                'operation' => $operationName
            ]);

            // Poll the operation until it's done (max 60 seconds polling)
            $maxAttempts = 12; // 12 attempts * 5 seconds = 60 seconds
            $attempt = 0;
            $operationUrl = self::API_BASE . '/' . $operationName;
            
            while ($attempt < $maxAttempts) {
                sleep(5); // Wait 5 seconds between polls
                $attempt++;
                
                $this->logger->info('Google Veo: Polling operation', [
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts
                ]);

                $statusResponse = $this->httpClient->request('GET', $operationUrl, [
                    'headers' => [
                        'x-goog-api-key' => $this->apiKey,
                    ],
                    'timeout' => 30,
                ]);

                $statusData = $statusResponse->toArray();
                
                if (isset($statusData['done']) && $statusData['done'] === true) {
                    // Operation completed!
                    $this->logger->info('Google Veo: Video generation completed!');
                    
                    // Extract video URI
                    $videoUri = $statusData['response']['generateVideoResponse']['generatedSamples'][0]['video']['uri'] ?? null;
                    
                    if (!$videoUri) {
                        throw new \Exception('No video URI in completed operation response');
                    }

                    // Download the video from the URI
                    $videoResponse = $this->httpClient->request('GET', $videoUri, [
                        'headers' => [
                            'x-goog-api-key' => $this->apiKey,
                        ],
                        'timeout' => 120,
                    ]);

                    $videoData = $videoResponse->getContent();
                    
                    // Convert to base64 data URL
                    $base64Video = base64_encode($videoData);
                    
                    return [[
                        'url' => 'data:video/mp4;base64,' . $base64Video,
                        'revised_prompt' => $prompt,
                        'duration' => 8, // Veo 3.1 generates 8-second videos
                    ]];
                }
            }

            throw new \Exception('Video generation timed out after ' . ($maxAttempts * 5) . ' seconds');
            
        } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
            // Log the full error response
            $this->logger->error('Google Veo: HTTP Error', [
                'status_code' => $e->getResponse()->getStatusCode(),
                'response_body' => $e->getResponse()->getContent(false),
                'url' => $e->getResponse()->getInfo('url') ?? 'unknown'
            ]);
            
            throw new ProviderException(
                'Google video generation error: ' . $e->getMessage(),
                'google'
            );
        } catch (\Exception $e) {
            $this->logger->error('Google Veo: General Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new ProviderException(
                'Google video generation error: ' . $e->getMessage(),
                'google'
            );
        }
    }

    public function editImage(string $imageUrl, string $maskUrl, string $prompt): string
    {
        // Google Gemini 2.5 Flash Image supports editing
        $model = 'gemini-2.5-flash-image-preview';
        
        if (!$this->apiKey) {
            throw ProviderException::missingApiKey('google', 'GOOGLE_GEMINI_API_KEY');
        }

        try {
            // Read the image
            $fullPath = $this->uploadDir . '/' . ltrim($imageUrl, '/');
            if (!file_exists($fullPath)) {
                throw new \Exception("Image file not found: {$fullPath}");
            }

            $imageData = file_get_contents($fullPath);
            $base64Image = base64_encode($imageData);
            $mimeType = mime_content_type($fullPath);

            $url = self::API_BASE . "/models/{$model}:generateContent";

            $payload = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            [
                                'text' => "Edit this image: {$prompt}"
                            ],
                            [
                                'inline_data' => [
                                    'mime_type' => $mimeType,
                                    'data' => $base64Image
                                ]
                            ]
                        ]
                    ]
                ],
            ];

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ],
                'json' => $payload,
                'timeout' => 60,
            ]);

            $data = $response->toArray();
            
            // Extract image from response
            if (isset($data['candidates'][0]['content']['parts'][0]['inline_data'])) {
                $imageBase64 = $data['candidates'][0]['content']['parts'][0]['inline_data']['data'];
                return 'data:image/png;base64,' . $imageBase64;
            }

            throw new \Exception('No image returned from Google');
        } catch (\Exception $e) {
            throw new ProviderException(
                'Google image edit error: ' . $e->getMessage(),
                'google'
            );
        }
    }

    // ==================== VISION ====================

    public function explainImage(string $imageUrl, string $prompt = '', array $options = []): string
    {
        $defaultPrompt = 'Describe what you see in this image in detail.';
        return $this->analyzeImage($imageUrl, $prompt ?: $defaultPrompt, $options);
    }

    public function extractTextFromImage(string $imageUrl): string
    {
        return $this->analyzeImage($imageUrl, 'Extract all text from this image. Return only the text, nothing else.');
    }

    public function compareImages(string $imageUrl1, string $imageUrl2): array
    {
        if (!$this->apiKey) {
            throw ProviderException::missingApiKey('google', 'GOOGLE_GEMINI_API_KEY');
        }

        try {
            $fullPath1 = $this->uploadDir . '/' . ltrim($imageUrl1, '/');
            $fullPath2 = $this->uploadDir . '/' . ltrim($imageUrl2, '/');
            
            if (!file_exists($fullPath1) || !file_exists($fullPath2)) {
                throw new \Exception("One or both images not found");
            }

            $imageData1 = file_get_contents($fullPath1);
            $imageData2 = file_get_contents($fullPath2);
            $base64Image1 = base64_encode($imageData1);
            $base64Image2 = base64_encode($imageData2);
            $mimeType1 = mime_content_type($fullPath1);
            $mimeType2 = mime_content_type($fullPath2);

            $model = 'gemini-2.5-pro-preview-06-05';
            $url = self::API_BASE . "/models/{$model}:generateContent";

            $payload = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            [
                                'text' => 'Compare these two images and describe the similarities and differences.'
                            ],
                            [
                                'inline_data' => [
                                    'mime_type' => $mimeType1,
                                    'data' => $base64Image1
                                ]
                            ],
                            [
                                'inline_data' => [
                                    'mime_type' => $mimeType2,
                                    'data' => $base64Image2
                                ]
                            ]
                        ]
                    ]
                ],
            ];

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ],
                'json' => $payload,
                'timeout' => 60,
            ]);

            $data = $response->toArray();
            $comparison = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            
            return [
                'comparison' => $comparison,
                'image1' => basename($imageUrl1),
                'image2' => basename($imageUrl2),
            ];
        } catch (\Exception $e) {
            throw new ProviderException(
                'Google image comparison error: ' . $e->getMessage(),
                'google'
            );
        }
    }

    public function analyzeImage(string $imagePath, string $prompt, array $options = []): string
    {
        if (!$this->apiKey) {
            throw ProviderException::missingApiKey('google', 'GOOGLE_GEMINI_API_KEY');
        }

        try {
            $model = $options['model'] ?? 'gemini-2.5-pro-preview-06-05';
            
            $fullPath = $this->uploadDir . '/' . ltrim($imagePath, '/');
            
            if (!file_exists($fullPath)) {
                throw new \Exception("Image file not found: {$fullPath}");
            }

            $imageData = file_get_contents($fullPath);
            $base64Image = base64_encode($imageData);
            $mimeType = mime_content_type($fullPath);

            $this->logger->info('Google: Analyzing image', [
                'model' => $model,
                'image' => basename($imagePath),
                'prompt_length' => strlen($prompt)
            ]);

            $url = self::API_BASE . "/models/{$model}:generateContent";

            $payload = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            [
                                'text' => $prompt
                            ],
                            [
                                'inline_data' => [
                                    'mime_type' => $mimeType,
                                    'data' => $base64Image
                                ]
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'maxOutputTokens' => $options['max_tokens'] ?? 1000,
                ],
            ];

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ],
                'json' => $payload,
                'timeout' => 60,
            ]);

            $data = $response->toArray();
            
            return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        } catch (\Exception $e) {
            throw new ProviderException(
                'Google vision error: ' . $e->getMessage(),
                'google'
            );
        }
    }

    // ==================== TEXT TO SPEECH ====================

    public function synthesize(string $text, array $options = []): string
    {
        if (!$this->apiKey) {
            throw ProviderException::missingApiKey('google', 'GOOGLE_GEMINI_API_KEY');
        }

        try {
            $model = $options['model'] ?? 'gemini-2.0-flash';
            
            $this->logger->info('Google: Synthesizing speech', [
                'model' => $model,
                'text_length' => strlen($text)
            ]);

            // Use Gemini to generate speech (experimental)
            $url = self::API_BASE . "/models/{$model}:generateContent";

            $payload = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            [
                                'text' => "Convert this text to speech: {$text}"
                            ]
                        ]
                    ]
                ],
            ];

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ],
                'json' => $payload,
                'timeout' => 60,
            ]);

            // For now, throw not implemented - Google TTS requires different API
            throw new ProviderException('Google TTS not yet implemented - use Google Cloud Text-to-Speech API', 'google');
        } catch (\Exception $e) {
            throw new ProviderException(
                'Google TTS error: ' . $e->getMessage(),
                'google'
            );
        }
    }

    public function getVoices(): array
    {
        // Google Cloud TTS voices would be loaded here
        return [];
    }

    // ==================== HELPER METHODS ====================

    /**
     * Convert OpenAI-style messages to Gemini format
     */
    private function convertMessagesToGeminiFormat(array $messages): array
    {
        $contents = [];
        
        foreach ($messages as $message) {
            $role = $message['role'] === 'assistant' ? 'model' : 'user';
            
            $parts = [];
            if (is_string($message['content'])) {
                $parts[] = ['text' => $message['content']];
            } elseif (is_array($message['content'])) {
                foreach ($message['content'] as $part) {
                    if ($part['type'] === 'text') {
                        $parts[] = ['text' => $part['text']];
                    } elseif ($part['type'] === 'image_url') {
                        $imageUrl = $part['image_url']['url'] ?? $part['image_url'];
                        if (str_starts_with($imageUrl, 'data:image')) {
                            // Base64 image
                            list($mime, $data) = explode(';', $imageUrl);
                            list(, $data) = explode(',', $data);
                            $parts[] = [
                                'inline_data' => [
                                    'mime_type' => str_replace('data:', '', $mime),
                                    'data' => $data
                                ]
                            ];
                        }
                    }
                }
            }
            
            $contents[] = [
                'role' => $role,
                'parts' => $parts
            ];
        }
        
        return $contents;
    }
}

