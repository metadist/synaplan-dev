<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\MessageFile;
use App\Entity\User;
use App\AI\Service\AiFacade;
use App\Service\Message\MessageProcessor;
use App\Service\AgainService;
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
        private AgainService $againService,
        private LoggerInterface $logger
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
                    'webSearch' => $webSearch,
                ];
                
                // Add model_id if specified (for "Again" functionality)
                if ($modelId) {
                    $processingOptions['model_id'] = (int) $modelId;
                    $this->logger->info('StreamController: Using specified model', [
                        'model_id' => $modelId
                    ]);
                }
                
                $result = $this->messageProcessor->processStream(
                    $incomingMessage,
                    // Stream callback - AI streams TEXT directly
                    function($chunk) use (&$responseText, &$chunkCount) {
                        if (connection_aborted()) {
                            error_log('🔴 StreamController: Connection aborted');
                            return;
                        }
                        
                        $responseText .= $chunk;
                        
                        // Stream immediately to frontend
                        if (!empty($chunk)) {
                            $this->sendSSE('data', ['chunk' => $chunk]);
                            
                            if ($chunkCount === 0) {
                                error_log('🔵 StreamController: Started streaming');
                            }
                            $chunkCount++;
                        }
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
                
                // Store detailed provider and model information in MessageMeta
                $outgoingMessage->setMeta('ai_provider', $response['metadata']['provider'] ?? 'unknown');
                $outgoingMessage->setMeta('ai_model', $response['metadata']['model'] ?? 'unknown');
                if (!empty($response['metadata']['usage'])) {
                    $outgoingMessage->setMeta('ai_usage', json_encode($response['metadata']['usage']));
                }
                
                // Update incoming message
                $incomingMessage->setTopic($classification['topic']);
                $incomingMessage->setLanguage($classification['language']);
                $incomingMessage->setStatus('complete');
                
                $chat->updateTimestamp();
                
                $this->em->flush();

                // Get Again data
                $this->logger->info('StreamController: Getting againData', [
                    'topic' => $classification['topic'],
                    'message_id' => $outgoingMessage->getId()
                ]);
                
                $againData = $this->getAgainData($classification['topic'], null);
                
                $this->logger->info('StreamController: AgainData retrieved', [
                    'eligible_count' => count($againData['eligible'] ?? []),
                    'has_predicted' => isset($againData['predictedNext']),
                    'tag' => $againData['tag'] ?? null
                ]);

                // Send complete event
                $this->sendSSE('complete', [
                    'messageId' => $outgoingMessage->getId(),
                    'trackId' => $trackId,
                    'provider' => $response['metadata']['provider'] ?? 'test',
                    'model' => $response['metadata']['model'] ?? 'unknown',
                    'topic' => $classification['topic'],
                    'language' => $classification['language'],
                    'again' => $againData,
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

    private function getAgainData(string $topic, ?int $currentModelId): array
    {
        $tag = $this->againService->resolveTagFromTopic($topic);
        $eligibleModels = $this->againService->getEligibleModels($tag);
        $predictedNext = $this->againService->getPredictedNext($eligibleModels, $currentModelId);

        return [
            'eligible' => $eligibleModels,
            'predictedNext' => $predictedNext,
            'tag' => $tag,
        ];
    }
}
