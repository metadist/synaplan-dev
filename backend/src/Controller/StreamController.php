<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\MessageFile;
use App\Entity\User;
use App\AI\Service\AiFacade;
use App\Service\Message\MessageProcessor;
use App\Service\ModelConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/messages', name: 'api_messages_')]
class StreamController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private AiFacade $aiFacade,
        private MessageProcessor $messageProcessor,
        private LoggerInterface $logger,
        private ModelConfigService $modelConfigService
    ) {}

    #[Route('/stream', name: 'stream', methods: ['GET'])]
    public function streamMessage(
        Request $request,
        #[CurrentUser] ?User $user
    ): Response {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $messageText = $request->query->get('message', '');
        $trackId = $request->query->get('trackId', time());
        $chatId = $request->query->get('chatId', null);
        $includeReasoning = $request->query->get('reasoning', '0') === '1';
        $webSearch = $request->query->get('webSearch', '0') === '1';
        $modelId = $request->query->get('modelId', null);
        
        error_log('🔍 StreamController: Query params - reasoning=' . $request->query->get('reasoning', 'NOT SET') . ', includeReasoning=' . ($includeReasoning ? 'TRUE' : 'FALSE'));
        $fileIds = $request->query->get('fileIds', ''); // NEW: comma-separated list or single ID

        // Parse fileIds (can be comma-separated string or single ID)
        $fileIdArray = [];
        if (!empty($fileIds)) {
            $fileIdArray = array_map('intval', array_filter(explode(',', $fileIds)));
        }

        if (empty($messageText)) {
            return $this->json(['error' => 'Message is required'], Response::HTTP_BAD_REQUEST);
        }
        
        if (!$chatId) {
            return $this->json(['error' => 'Chat ID is required'], Response::HTTP_BAD_REQUEST);
        }
        
        $this->logger->info('StreamController: Received request', [
            'user_id' => $user->getId(),
            'chat_id' => $chatId,
            'has_model_id' => $modelId !== null,
            'model_id' => $modelId,
            'file_ids' => $fileIdArray,
            'file_count' => count($fileIdArray)
        ]);

        // StreamedResponse für SSE
        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        $response->setCallback(function () use ($user, $messageText, $trackId, $chatId, $includeReasoning, $webSearch, $modelId, $fileIdArray) {
            // Disable output buffering
            while (ob_get_level()) {
                ob_end_clean();
            }
            ob_implicit_flush(1);
            set_time_limit(0);
            ignore_user_abort(false);

            try {
                // Load chat
                $chat = $this->em->getRepository(\App\Entity\Chat::class)->find((int)$chatId);
                if (!$chat || $chat->getUserId() !== $user->getId()) {
                    $this->sendSSE('error', ['error' => 'Chat not found or access denied']);
                    return;
                }
                
                // Create incoming message
                $incomingMessage = new Message();
                $incomingMessage->setUserId($user->getId());
                $incomingMessage->setChat($chat);
                $incomingMessage->setTrackingId($trackId);
                $incomingMessage->setProviderIndex('WEB');
                $incomingMessage->setUnixTimestamp(time());
                $incomingMessage->setDateTime(date('YmdHis'));
                $incomingMessage->setMessageType('WEB');
                $incomingMessage->setFile(0);
                $incomingMessage->setTopic('CHAT');
                $incomingMessage->setLanguage('en');
                $incomingMessage->setText($messageText);
                $incomingMessage->setDirection('IN');
                $incomingMessage->setStatus('processing');
                
                $this->em->persist($incomingMessage);
                $this->em->flush(); // Flush first so message has an ID
                
                // Attach multiple files if uploaded (NEW: MessageFile entities)
                if (!empty($fileIdArray)) {
                    $fileCount = 0;
                    foreach ($fileIdArray as $fileId) {
                        $messageFile = $this->em->getRepository(MessageFile::class)->find($fileId);
                        // Accept files in any status (uploaded, extracted, vectorized)
                        if ($messageFile && $messageFile->getUserId() === $user->getId()) {
                            // Associate file with message
                            $messageFile->setMessageId($incomingMessage->getId());
                            $this->em->persist($messageFile);
                            $fileCount++;
                            
                            $this->logger->info('StreamController: File attached to message', [
                                'message_id' => $incomingMessage->getId(),
                                'file_id' => $fileId,
                                'file_path' => $messageFile->getFilePath(),
                                'file_type' => $messageFile->getFileType(),
                                'file_status' => $messageFile->getStatus()
                            ]);
                        }
                    }
                    
                    if ($fileCount > 0) {
                        // Legacy: set file flag for compatibility
                        $incomingMessage->setFile($fileCount);
                        $this->em->flush();
                        
                        // CRITICAL: Force reload of the entity with files collection!
                        // refresh() doesn't work reliably for collections, so we use clear() + find()
                        $messageId = $incomingMessage->getId();
                        $chatId = $incomingMessage->getChatId();
                        
                        $this->em->clear(); // Detach all entities
                        
                        // Reload message with files
                        $incomingMessage = $this->em->getRepository(Message::class)->find($messageId);
                        
                        if (!$incomingMessage) {
                            $this->logger->error('StreamController: Message not found after refresh!', [
                                'message_id' => $messageId
                            ]);
                            $this->sendSSE('error', ['message' => 'Internal error: Message lost']);
                            return;
                        }
                        
                        // Reload chat to avoid cascade persist error
                        if ($chatId) {
                            $chat = $this->em->getRepository(\App\Entity\Chat::class)->find($chatId);
                            if ($chat) {
                                $incomingMessage->setChat($chat);
                            }
                        }
                        
                        $this->logger->info('StreamController: Files attached and entity reloaded', [
                            'message_id' => $incomingMessage->getId(),
                            'files_count' => $incomingMessage->getFiles()->count()
                        ]);
                        
                        // Send preprocessing status to frontend
                        $this->sendSSE('status', [
                            'message' => "Processing $fileCount file(s)...",
                            'stage' => 'preprocessing',
                            'file_count' => $fileCount
                        ]);
                    }
                }

                // Process with REAL streaming (TEXT only, NO JSON!)
                $responseText = '';
                $chunkCount = 0;
                
                $processingOptions = [
                    'reasoning' => $includeReasoning,
                    'web_search' => $webSearch, // Use snake_case for consistency with backend
                ];
                
                // Add model_id if specified (for "Again" functionality)
                if ($modelId) {
                    $processingOptions['model_id'] = (int) $modelId;
                    $this->logger->info('StreamController: Using specified model', [
                        'model_id' => $modelId
                    ]);
                }
                
                // Check if selected model supports streaming
                $supportsStreaming = true;
                if ($modelId) {
                    $supportsStreaming = $this->modelConfigService->supportsStreaming((int) $modelId);
                    error_log('🔍 Model supports streaming: ' . ($supportsStreaming ? 'YES' : 'NO'));
                }
                
                // Route to streaming or non-streaming handler
                if (!$supportsStreaming) {
                    // Non-streaming models (e.g., o1-preview, o1-mini)
                    $this->handleNonStreamingRequest($incomingMessage, $processingOptions);
                    return $response;
                }
                
                // Regular streaming path
                $result = $this->messageProcessor->processStream(
                    $incomingMessage,
                    // Stream callback - AI streams TEXT directly or structured data (reasoning)
                    function($chunk) use (&$responseText, &$chunkCount) {
                        if (connection_aborted()) {
                            error_log('🔴 StreamController: Connection aborted');
                            return;
                        }
                        
                        // Handle structured chunk (reasoning models)
                        if (is_array($chunk)) {
                            $type = $chunk['type'] ?? 'content';
                            $content = $chunk['content'] ?? '';
                            
                            if ($type === 'reasoning') {
                                // Send reasoning chunks separately
                                $this->sendSSE('reasoning', ['chunk' => $content]);
                            } else {
                                // Regular content
                                $responseText .= $content;
                                if (!empty($content)) {
                                    $this->sendSSE('data', ['chunk' => $content]);
                                }
                            }
                        } else {
                            // Legacy string chunks (non-reasoning models)
                            $responseText .= $chunk;
                            
                            if (!empty($chunk)) {
                                $this->sendSSE('data', ['chunk' => $chunk]);
                            }
                        }
                        
                        if ($chunkCount === 0) {
                            error_log('🔵 StreamController: Started streaming');
                        }
                        $chunkCount++;
                    },
                    // Status callback
                    function($statusUpdate) {
                        if ($statusUpdate['status'] === 'complete') {
                            return;
                        }
                        
                        $this->sendSSE($statusUpdate['status'], [
                            'message' => $statusUpdate['message'],
                            'metadata' => $statusUpdate['metadata'] ?? [],
                            'timestamp' => $statusUpdate['timestamp']
                        ]);
                    },
                    $processingOptions
                );

                if (!$result['success']) {
                    // Build user-friendly error message as AI response
                    $isDev = $this->getParameter('kernel.environment') === 'dev';
                    
                    $errorMessage = "## ⚠️ " . $result['error'] . "\n\n";
                    
                    // Add installation instructions ONLY in dev mode
                    if ($isDev && isset($result['context'])) {
                        $context = $result['context'];
                        
                        // If a specific model was requested, show it prominently
                        if (isset($context['requested_model']) && isset($context['install_command'])) {
                            $errorMessage .= "### 💡 Install the Model You Selected\n\n";
                            $errorMessage .= "```bash\n" . $context['install_command'] . "\n```\n\n";
                        }
                        
                        // Show alternative models if available
                        if (isset($context['suggested_models'])) {
                            $errorMessage .= "### 📦 Or Try These Alternatives\n\n";
                            
                            if (isset($context['suggested_models']['quick'])) {
                                $errorMessage .= "**Quick & Light:**\n";
                                foreach ($context['suggested_models']['quick'] as $model) {
                                    $errorMessage .= "- `{$model}`\n";
                                }
                                $errorMessage .= "\n";
                            }
                            
                            if (isset($context['suggested_models']['medium'])) {
                                $errorMessage .= "**Medium (Better Quality):**\n";
                                foreach ($context['suggested_models']['medium'] as $model) {
                                    $errorMessage .= "- `{$model}`\n";
                                }
                                $errorMessage .= "\n";
                            }
                            
                            if (isset($context['suggested_models']['large'])) {
                                $errorMessage .= "**Large (Best Quality):**\n";
                                foreach ($context['suggested_models']['large'] as $model) {
                                    $errorMessage .= "- `{$model}`\n";
                                }
                                $errorMessage .= "\n";
                            }
                        }
                        
                        $errorMessage .= "*After downloading, refresh the page and try again.*";
                    } elseif (!$isDev) {
                        // Production: Generic message without technical details
                        $errorMessage .= "*Please contact your system administrator or try selecting a different AI model.*";
                    }
                    
                    // Stream the error message as data chunks (like normal AI response)
                    $this->sendSSE('data', ['chunk' => $errorMessage]);
                    
                    // Save error message to database
                    $outgoingMessage = new Message();
                    $outgoingMessage->setUserId($user->getId());
                    $outgoingMessage->setChat($chat);
                    $outgoingMessage->setTrackingId($trackId);
                    $outgoingMessage->setProviderIndex($incomingMessage->getProviderIndex()); // Use same channel as incoming
                    $outgoingMessage->setUnixTimestamp(time());
                    $outgoingMessage->setDateTime(date('YmdHis'));
                    $outgoingMessage->setMessageType('WEB');
                    $outgoingMessage->setFile(0);
                    $outgoingMessage->setTopic('ERROR');
                    $outgoingMessage->setLanguage('en');
                    $outgoingMessage->setText($errorMessage);
                    $outgoingMessage->setDirection('OUT');
                    $outgoingMessage->setStatus('complete');
                    
                    $this->em->persist($outgoingMessage);
                    $this->em->flush(); // Flush to get message ID for metadata
                    
                    // Store error details in metadata
                    $outgoingMessage->setMeta('ai_provider', $result['provider'] ?? 'system');
                    $outgoingMessage->setMeta('ai_model', 'error');
                    $outgoingMessage->setMeta('error_type', $result['error'] ?? 'unknown');
                    
                    // Update incoming message
                    $incomingMessage->setTopic('ERROR');
                    $incomingMessage->setStatus('error');
                    
                    $chat->updateTimestamp();
                    $this->em->flush();
                    
                    // Send complete event
                    $this->sendSSE('complete', [
                        'messageId' => $outgoingMessage->getId(),
                        'trackId' => $trackId,
                        'provider' => $result['provider'] ?? 'system',
                        'model' => 'error',
                        'topic' => 'ERROR',
                        'language' => 'en',
                    ]);
                    
                    return; // Exit early
                }

                $classification = $result['classification'];
                $response = $result['response'];
                
                error_log('🔵 StreamController: Streaming complete, ' . $chunkCount . ' chunks');
                $this->logger->info('StreamController: Streaming complete', [
                    'chunks' => $chunkCount,
                    'length' => strlen($responseText),
                ]);

                // Get file/links from handler metadata (Handler sets these, not AI!)
                $hasFile = 0;
                $filePath = '';
                $fileType = '';
                
                if (isset($response['metadata']['file'])) {
                    $hasFile = 1;
                    $filePath = $response['metadata']['file']['path'];
                    $fileType = $response['metadata']['file']['type'];
                    
                    $this->sendSSE('file', [
                        'type' => $fileType,
                        'url' => $filePath,
                    ]);
                    
                    $this->logger->info('StreamController: Handler provided file', [
                        'path' => $filePath,
                        'type' => $fileType
                    ]);
                }
                
                if (isset($response['metadata']['links'])) {
                    $this->sendSSE('links', [
                        'links' => $response['metadata']['links']
                    ]);
                    $this->logger->info('StreamController: Handler provided links');
                }

                // Create outgoing message
                $outgoingMessage = new Message();
                $outgoingMessage->setUserId($user->getId());
                $outgoingMessage->setChat($chat);
                $outgoingMessage->setTrackingId($trackId);
                $outgoingMessage->setProviderIndex($incomingMessage->getProviderIndex()); // Use same channel as incoming
                $outgoingMessage->setUnixTimestamp(time());
                $outgoingMessage->setDateTime(date('YmdHis'));
                $outgoingMessage->setMessageType('WEB');
                $outgoingMessage->setFile($hasFile);
                $outgoingMessage->setFilePath($filePath);
                $outgoingMessage->setFileType($fileType);
                $outgoingMessage->setTopic($classification['topic']);
                $outgoingMessage->setLanguage($classification['language']);
                $outgoingMessage->setText($responseText); // Pure TEXT, not JSON
                $outgoingMessage->setDirection('OUT');
                $outgoingMessage->setStatus('complete');

                $this->em->persist($outgoingMessage);
                $this->em->flush(); // Flush to get message ID for metadata
                
                // DEBUG: Log what we're about to save
                error_log('🔍 CHAT MODEL: ' . ($response['metadata']['provider'] ?? 'unknown') . ' / ' . ($response['metadata']['model'] ?? 'unknown'));
                error_log('🔍 SORTING MODEL: ' . ($classification['sorting_provider'] ?? 'null') . ' / ' . ($classification['sorting_model_name'] ?? 'null') . ' (ID: ' . ($classification['sorting_model_id'] ?? 'null') . ')');
                
                $this->logger->info('🔍 StreamController: Saving model metadata', [
                    'chat_provider' => $response['metadata']['provider'] ?? 'unknown',
                    'chat_model' => $response['metadata']['model'] ?? 'unknown',
                    'sorting_provider' => $classification['sorting_provider'] ?? null,
                    'sorting_model' => $classification['sorting_model_name'] ?? null,
                    'sorting_model_id' => $classification['sorting_model_id'] ?? null
                ]);
                
                // Store CHAT model information in MessageMeta
                $outgoingMessage->setMeta('ai_chat_provider', $response['metadata']['provider'] ?? 'unknown');
                $outgoingMessage->setMeta('ai_chat_model', $response['metadata']['model'] ?? 'unknown');
                
                // Store CHAT model_id if available (from user selection or resolved by ChatHandler)
                if (!empty($modelId)) {
                    $outgoingMessage->setMeta('ai_chat_model_id', (string)$modelId);
                    $this->logger->info('StreamController: Storing chat model ID from user selection', [
                        'model_id' => $modelId
                    ]);
                } elseif (!empty($response['metadata']['model_id'])) {
                    $outgoingMessage->setMeta('ai_chat_model_id', (string)$response['metadata']['model_id']);
                    $this->logger->info('StreamController: Storing chat model ID from response', [
                        'model_id' => $response['metadata']['model_id']
                    ]);
                }
                
                if (!empty($response['metadata']['usage'])) {
                    $outgoingMessage->setMeta('ai_chat_usage', json_encode($response['metadata']['usage']));
                }
                
                // Store SORTING model information in MessageMeta (from classification)
                if (!empty($classification['sorting_provider'])) {
                    $outgoingMessage->setMeta('ai_sorting_provider', $classification['sorting_provider']);
                }
                if (!empty($classification['sorting_model_name'])) {
                    $outgoingMessage->setMeta('ai_sorting_model', $classification['sorting_model_name']);
                }
                if (!empty($classification['sorting_model_id'])) {
                    $outgoingMessage->setMeta('ai_sorting_model_id', (string)$classification['sorting_model_id']);
                }
                
                // Store Web Search metadata if web search was used
                if ($webSearch) {
                    $incomingMessage->setMeta('web_search_enabled', 'true');
                    $this->logger->info('StreamController: Web search was enabled for this message');
                }
                
                // Store if search results were found (will be processed below)
                $hasSearchResults = isset($result['search_results']) && !empty($result['search_results']['results']);
                if ($hasSearchResults) {
                    $searchQuery = $result['search_results']['query'] ?? '';
                    $searchCount = count($result['search_results']['results']);
                    
                    $incomingMessage->setMeta('web_search_query', $searchQuery);
                    $incomingMessage->setMeta('web_search_results_count', (string)$searchCount);
                    $outgoingMessage->setMeta('web_search_query', $searchQuery);
                    $outgoingMessage->setMeta('web_search_results_count', (string)$searchCount);
                    
                    $this->logger->info('StreamController: Stored search results metadata', [
                        'query' => $searchQuery,
                        'results_count' => $searchCount
                    ]);
                }
                
                // Update incoming message
                $incomingMessage->setTopic($classification['topic']);
                $incomingMessage->setLanguage($classification['language']);
                $incomingMessage->setStatus('complete');
                
                $chat->updateTimestamp();
                
                $this->em->flush();

                // Get search results if available
                $searchResults = null;
                if (isset($result['search_results']) && !empty($result['search_results']['results'])) {
                    $searchResults = array_map(function($result) {
                        return [
                            'title' => $result['title'] ?? '',
                            'url' => $result['url'] ?? '',
                            'description' => $result['description'] ?? '',
                            'published' => $result['age'] ?? null,
                            'source' => $result['profile']['name'] ?? null,
                            'thumbnail' => $result['thumbnail'] ?? null,
                        ];
                    }, $result['search_results']['results']);
                    
                    $this->logger->info('StreamController: Including search results', [
                        'results_count' => count($searchResults),
                        'query' => $result['search_results']['query']
                    ]);
                }

                // Send complete event (WITHOUT againData - frontend handles this)
                $this->sendSSE('complete', [
                    'messageId' => $outgoingMessage->getId(),
                    'trackId' => $trackId,
                    'provider' => $response['metadata']['provider'] ?? 'test',
                    'model' => $response['metadata']['model'] ?? 'unknown',
                    'topic' => $classification['topic'],
                    'language' => $classification['language'],
                    'searchResults' => $searchResults, // Include search results
                ]);
                
                usleep(100000);

                $this->logger->info('Streamed message processed', [
                    'user_id' => $user->getId(),
                    'message_id' => $outgoingMessage->getId(),
                    'topic' => $classification['topic'],
                ]);

            } catch (\App\AI\Exception\ProviderException $e) {
                $this->logger->error('AI Provider failed', [
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage(),
                    'provider' => $e->getProviderName(),
                    'context' => $e->getContext(),
                ]);

                $errorData = [
                    'error' => $e->getMessage(),
                    'provider' => $e->getProviderName(),
                ];
                
                // Add installation instructions if available
                if ($context = $e->getContext()) {
                    $errorData['install_command'] = $context['install_command'] ?? null;
                    $errorData['suggested_models'] = $context['suggested_models'] ?? null;
                }

                $this->sendSSE('error', $errorData);
            } catch (\Exception $e) {
                $this->logger->error('Streaming failed', [
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage(),
                ]);

                $this->sendSSE('error', [
                    'error' => 'Failed to process message: ' . $e->getMessage(),
                ]);
            }
        });

        return $response;
    }

    /**
     * Handle non-streaming requests for models that don't support streaming (e.g., o1-preview)
     */
    private function handleNonStreamingRequest(\App\Entity\Message $message, array $options): void
    {
        try {
            // Send processing status
            $this->sendSSE('status', ['message' => 'Processing with non-streaming model...']);
            
            // Process message without streaming
            $result = $this->messageProcessor->process($message, $options);
            
            if (!$result['success']) {
                $this->sendSSE('error', ['error' => $result['error']]);
                return;
            }
            
            // Get response content
            $content = $result['content'] ?? '';
            $metadata = $result['metadata'] ?? [];
            
            // Extract reasoning if present (for o1 models)
            $reasoning = null;
            if (isset($metadata['reasoning'])) {
                $reasoning = $metadata['reasoning'];
                unset($metadata['reasoning']);
            }
            
            // Send reasoning first if available
            if ($reasoning) {
                $this->sendSSE('reasoning_complete', ['reasoning' => $reasoning]);
            }
            
            // Send content in one chunk (simulating streaming)
            $this->sendSSE('data', ['chunk' => $content]);
            
            // Send complete event
            $this->sendSSE('complete', [
                'messageId' => $message->getId(),
                'provider' => $metadata['provider'] ?? 'unknown',
                'model' => $metadata['model'] ?? 'unknown',
                'trackId' => $_GET['trackId'] ?? time(),
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Non-streaming processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendSSE('error', ['error' => 'Failed to process: ' . $e->getMessage()]);
        }
    }

    private function sendSSE(string $status, array $data): void
    {
        if (connection_aborted()) {
            error_log('🔴 StreamController: Connection aborted');
            return;
        }

        $event = [
            'status' => $status,
            ...$data,
        ];

        echo "data: " . json_encode($event) . "\n\n";
        
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

}
