<?php

namespace App\Service\Message;

use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Repository\SearchResultRepository;
use App\Service\Message\MessagePreProcessor;
use App\Service\Message\MessageClassifier;
use App\Service\Message\InferenceRouter;
use App\Service\Message\SearchQueryGenerator;
use App\Service\ModelConfigService;
use App\Service\Search\BraveSearchService;
use Psr\Log\LoggerInterface;

/**
 * Message Processor with Status Callbacks
 * 
 * Orchestrates the complete message processing pipeline:
 * 1. Preprocessing (file download, parsing)
 * 2. Classification (sorting, topic detection)
 * 3. Web Search (if needed)
 * 4. Inference (AI response generation)
 * 
 * Provides status callbacks for frontend feedback
 */
class MessageProcessor
{
    public function __construct(
        private MessageRepository $messageRepository,
        private ?SearchResultRepository $searchResultRepository,
        private MessagePreProcessor $preProcessor,
        private MessageClassifier $classifier,
        private InferenceRouter $router,
        private ModelConfigService $modelConfigService,
        private BraveSearchService $braveSearchService,
        private SearchQueryGenerator $searchQueryGenerator,
        private LoggerInterface $logger
    ) {}

    /**
     * Process a message with streaming support
     * 
     * @param Message $message The message to process
     * @param callable $streamCallback Callback for response chunks
     * @param callable|null $statusCallback Callback for status updates
     * @param array $options Processing options (e.g., reasoning, temperature)
     * @return array Processing result with metadata
     */
    public function processStream(Message $message, callable $streamCallback, ?callable $statusCallback = null, array $options = []): array
    {
        $this->notify($statusCallback, 'started', 'Message processing started');

        try {
            // Step 1: Preprocessing (modifies Message entity in-place)
            $this->notify($statusCallback, 'preprocessing', 'Downloading and parsing files...');
            
            $message = $this->preProcessor->process($message);
            $preprocessed = ['hasFiles' => $message->getFile() > 0];
            
            if ($message->getFile() > 0 && $message->getFileText()) {
                $this->notify($statusCallback, 'preprocessing', 'File processed and text extracted');
            }

            // Check if this is "Again" functionality (model explicitly specified)
            // If so, skip classification to save time and API calls
            $isAgainRequest = isset($options['model_id']) && $options['model_id'];

            // Step 2: Classification (Sorting) - skip if "Again"
            $sortingModelId = null;
            $sortingProvider = null;
            $sortingModelName = null;
            $conversationHistory = [];
            
            if ($isAgainRequest) {
                // Skip classification for "Again" - use specified model directly
                $this->logger->info('MessageProcessor: Skipping classification (Again request)', [
                    'specified_model_id' => $options['model_id']
                ]);
                
                $this->notify($statusCallback, 'classified', 'Using previously selected model (skipped classification)');
                
                // Minimal classification with specified model
                $classification = [
                    'topic' => 'chat',
                    'language' => 'en',
                    'source' => 'chat',
                    'model_id' => $options['model_id'] // This goes to ChatHandler
                ];
            } else {
                // Normal flow: Run classification
                // Get sorting model info to display during classification
                $sortingModelId = $this->modelConfigService->getDefaultModel('SORT', $message->getUserId());
                if ($sortingModelId) {
                    $sortingProvider = $this->modelConfigService->getProviderForModel($sortingModelId);
                    $sortingModelName = $this->modelConfigService->getModelName($sortingModelId);
                }
                
                $this->notify($statusCallback, 'classifying', 'Analyzing message intent...', [
                    'model_id' => $sortingModelId,
                    'provider' => $sortingProvider,
                    'model_name' => $sortingModelName
                ]);
            }
            
            // Get conversation history for context - STREAMING VERSION
            // Priority: Use chatId if available (chat window context), otherwise fall back to trackingId
            if ($message->getChatId()) {
                $conversationHistory = $this->messageRepository->findChatHistory(
                    $message->getUserId(),
                    $message->getChatId(),
                    30,      // Max 30 messages
                    15000    // Max ~15k chars (~4k tokens)
                );
                $this->logger->debug('Using chat history for streaming', [
                    'chat_id' => $message->getChatId(),
                    'history_count' => count($conversationHistory)
                ]);
            } else {
                // Fallback for legacy messages without chatId
                $conversationHistory = $this->messageRepository->findConversationHistory(
                    $message->getUserId(),
                    $message->getTrackingId(),
                    10
                );
                $this->logger->debug('Using legacy trackingId history for streaming', [
                    'tracking_id' => $message->getTrackingId(),
                    'history_count' => count($conversationHistory)
                ]);
            }

            if (!$isAgainRequest) {
                // Run classification
                $classification = $this->classifier->classify($message, $conversationHistory);
                
                // IMPORTANT: Save sorting model info separately (don't pass to ChatHandler!)
                $sortingModelId = $classification['model_id'] ?? null;
                $sortingProvider = $classification['provider'] ?? null;
                $sortingModelName = $classification['model_name'] ?? null;
                
                // Remove sorting model info from classification
                unset($classification['model_id']);
                unset($classification['provider']);
                unset($classification['model_name']);
                
                $this->notify($statusCallback, 'classified', sprintf(
                    'Topic: %s, Language: %s, Source: %s',
                    $classification['topic'],
                    $classification['language'],
                    $classification['source']
                ), [
                    'topic' => $classification['topic'],
                    'language' => $classification['language'],
                    'source' => $classification['source'],
                    'sorting_model_id' => $sortingModelId,
                    'sorting_provider' => $sortingProvider,
                    'sorting_model_name' => $sortingModelName
                ]);
            }

            // Step 2.5: Web Search (if requested or AI-classified)
            $searchResults = null;
            $shouldSearch = $options['web_search'] ?? false;
            
            // Check if AI classifier detected search intent automatically
            if (!$shouldSearch && isset($classification['web_search'])) {
                $shouldSearch = (bool)$classification['web_search'];
                
                if ($shouldSearch) {
                    $this->logger->info('🤖 AI Classifier activated web search automatically', [
                        'message_id' => $message->getId(),
                        'classification' => $classification
                    ]);
                }
            }
            
            // Also check if classifier set a search-related topic (legacy fallback)
            if (!$shouldSearch && isset($classification['source'])) {
                $source = $classification['source'];
                $shouldSearch = in_array($source, ['tools:search', 'tools:web'], true);
            }
            
            if ($shouldSearch && $this->braveSearchService->isEnabled()) {
                $this->notify($statusCallback, 'searching', 'Searching the web...');
                
                try {
                    // Generate optimized search query using AI
                    $searchQuery = $this->searchQueryGenerator->generate(
                        $message->getText(),
                        $message->getUserId()
                    );
                    
                    // Get language from classification (e.g., "de", "en", "fr")
                    // Use it directly as both search_lang and country (ISO 639-1 codes)
                    $language = $classification['language'] ?? 'en';
                    
                    // Use language code as country code (most languages match their country code)
                    // Brave Search will handle it gracefully and fall back if needed
                    $country = strtolower($language);
                    
                    $this->logger->info('🔍 Performing web search', [
                        'original_question' => $message->getText(),
                        'optimized_query' => $searchQuery,
                        'language' => $language,
                        'country' => $country,
                        'message_id' => $message->getId()
                    ]);
                    
                    // Pass language and country to search service
                    $searchResults = $this->braveSearchService->search($searchQuery, [
                        'country' => $country,
                        'search_lang' => $language
                    ]);
                    
                    // Save search results to database
                    if ($searchResults && !empty($searchResults['results']) && $this->searchResultRepository) {
                        $this->searchResultRepository->saveSearchResults($message, $searchResults, $searchQuery);
                        
                        $this->notify($statusCallback, 'search_complete', sprintf(
                            'Found %d web results',
                            count($searchResults['results'])
                        ), [
                            'results_count' => count($searchResults['results'])
                        ]);
                    } else {
                        $this->logger->warning('No search results found or repository not available', [
                            'query' => $searchQuery,
                            'has_repository' => $this->searchResultRepository !== null
                        ]);
                        $searchResults = null; // Reset to null if no results
                    }
                    
                } catch (\Exception $e) {
                    $this->logger->error('Web search failed', [
                        'error' => $e->getMessage(),
                        'message_id' => $message->getId()
                    ]);
                    
                    // Continue processing even if search fails
                    $this->notify($statusCallback, 'search_failed', 'Web search failed, continuing without results');
                }
            }

            // Step 3: Inference (AI Response) mit STREAMING
            // Get chat model info to display during generation
            $chatModelId = $this->modelConfigService->getDefaultModel('CHAT', $message->getUserId());
            $chatProvider = null;
            $chatModelName = null;
            if ($chatModelId) {
                $chatProvider = $this->modelConfigService->getProviderForModel($chatModelId);
                $chatModelName = $this->modelConfigService->getModelName($chatModelId);
            }
            
            $this->notify($statusCallback, 'generating', 'Generating response...', [
                'model_id' => $chatModelId,
                'provider' => $chatProvider,
                'model_name' => $chatModelName
            ]);
            
            // Use routeStream instead of route, pass options through
            // Include search results if available
            if ($searchResults) {
                $options['search_results'] = $searchResults;
            }
            
            $response = $this->router->routeStream($message, $conversationHistory, $classification, $streamCallback, $statusCallback, $options);
            
            // Re-add sorting model info to result (for StreamController to save)
            $classification['sorting_model_id'] = $sortingModelId;
            $classification['sorting_provider'] = $sortingProvider;
            $classification['sorting_model_name'] = $sortingModelName;
            
            // Note: content is streamed, not returned
            return [
                'success' => true,
                'classification' => $classification,
                'response' => $response,
                'preprocessed' => $preprocessed,
                'search_results' => $searchResults, // Include search results in return
            ];
        } catch (\App\AI\Exception\ProviderException $e) {
            // Handle ProviderException specially to preserve context (install instructions, etc.)
            $this->logger->error('AI Provider failed', [
                'error' => $e->getMessage(),
                'provider' => $e->getProviderName(),
                'context' => $e->getContext(),
            ]);

            $errorResult = [
                'success' => false,
                'error' => $e->getMessage(),
                'provider' => $e->getProviderName(),
            ];
            
            // Include context data (install_command, suggested_models) if available
            if ($context = $e->getContext()) {
                $errorResult['context'] = $context;
            }

            return $errorResult;
        } catch (\Exception $e) {
            $this->logger->error('Message processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process a message with status callbacks
     * 
     * @param Message $message The message to process
     * @param callable|null $statusCallback Callback for status updates
     * @return array Processing result
     */
    public function process(Message $message, ?callable $statusCallback = null): array
    {
        $this->notify($statusCallback, 'started', 'Message processing started');

        try {
            // Step 1: Preprocessing (modifies Message entity in-place)
            $this->notify($statusCallback, 'preprocessing', 'Downloading and parsing files...');
            
            $message = $this->preProcessor->process($message);
            $preprocessed = ['hasFiles' => $message->getFile() > 0];
            
            if ($message->getFile() > 0 && $message->getFileText()) {
                $this->notify($statusCallback, 'preprocessing', 'File processed and text extracted');
            }

            // Step 2: Classification (Sorting)
            // Get sorting model info to display during classification
            $sortingModelId = $this->modelConfigService->getDefaultModel('SORT', $message->getUserId());
            $sortingProvider = null;
            $sortingModelName = null;
            if ($sortingModelId) {
                $sortingProvider = $this->modelConfigService->getProviderForModel($sortingModelId);
                $sortingModelName = $this->modelConfigService->getModelName($sortingModelId);
            }
            
            $this->notify($statusCallback, 'classifying', 'Analyzing message intent...', [
                'model_id' => $sortingModelId,
                'provider' => $sortingProvider,
                'model_name' => $sortingModelName
            ]);
            
            // Get conversation history for context - NON-STREAMING VERSION
            // Priority: Use chatId if available (chat window context), otherwise fall back to trackingId
            if ($message->getChatId()) {
                $conversationHistory = $this->messageRepository->findChatHistory(
                    $message->getUserId(),
                    $message->getChatId(),
                    30,      // Max 30 messages
                    15000    // Max ~15k chars (~4k tokens)
                );
                $this->logger->debug('Using chat history for non-streaming', [
                    'chat_id' => $message->getChatId(),
                    'history_count' => count($conversationHistory)
                ]);
            } else {
                // Fallback for legacy messages without chatId
                $conversationHistory = $this->messageRepository->findConversationHistory(
                    $message->getUserId(),
                    $message->getTrackingId(),
                    10
                );
                $this->logger->debug('Using legacy trackingId history for non-streaming', [
                    'tracking_id' => $message->getTrackingId(),
                    'history_count' => count($conversationHistory)
                ]);
            }

            $classification = $this->classifier->classify($message, $conversationHistory);
            
            $this->notify($statusCallback, 'classified', sprintf(
                'Topic: %s, Language: %s, Source: %s',
                $classification['topic'],
                $classification['language'],
                $classification['source']
            ), [
                'topic' => $classification['topic'],
                'language' => $classification['language'],
                'source' => $classification['source'],
                'model_id' => $classification['model_id'] ?? null,
                'provider' => $classification['provider'] ?? null,
                'model_name' => $classification['model_name'] ?? null
            ]);

            // Step 3: Inference (AI Response)
            // Get chat model info to display during generation
            $chatModelId = $this->modelConfigService->getDefaultModel('CHAT', $message->getUserId());
            $chatProvider = null;
            $chatModelName = null;
            if ($chatModelId) {
                $chatProvider = $this->modelConfigService->getProviderForModel($chatModelId);
                $chatModelName = $this->modelConfigService->getModelName($chatModelId);
            }
            
            $this->notify($statusCallback, 'generating', 'Generating response...', [
                'model_id' => $chatModelId,
                'provider' => $chatProvider,
                'model_name' => $chatModelName
            ]);
            
            $response = $this->router->route($message, $conversationHistory, $classification, $statusCallback);
            
            $this->notify($statusCallback, 'complete', 'Response generated', [
                'provider' => $response['metadata']['provider'] ?? 'unknown',
                'model' => $response['metadata']['model'] ?? 'unknown',
            ]);

            return [
                'success' => true,
                'response' => $response,
                'classification' => $classification,
                'preprocessing' => $preprocessed
            ];

        } catch (\App\AI\Exception\ProviderException $e) {
            // Handle ProviderException specially to preserve context (install instructions, etc.)
            $this->logger->error('AI Provider failed', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
                'provider' => $e->getProviderName(),
                'context' => $e->getContext(),
            ]);
            
            error_log('🔴 AI PROVIDER FAILED: ' . $e->getMessage());

            $this->notify($statusCallback, 'error', $e->getMessage());

            $errorResult = [
                'success' => false,
                'error' => $e->getMessage(),
                'provider' => $e->getProviderName(),
            ];
            
            // Include context data (install_command, suggested_models) if available
            if ($context = $e->getContext()) {
                $errorResult['context'] = $context;
            }

            return $errorResult;
        } catch (\Throwable $e) {
            $errorDetails = [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ];
            
            $this->logger->error('Message processing failed', $errorDetails);
            
            // Also dump to stderr for immediate visibility
            error_log('🔴 MESSAGE PROCESSING FAILED: ' . $e->getMessage());
            error_log('File: ' . $e->getFile() . ':' . $e->getLine());

            $this->notify($statusCallback, 'error', $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'details' => $errorDetails
            ];
        }
    }

    /**
     * Send status notification to callback
     */
    private function notify(?callable $callback, string $status, string $message, array $metadata = []): void
    {
        if ($callback) {
            $callback([
                'status' => $status,
                'message' => $message,
                'metadata' => $metadata,
                'timestamp' => microtime(true)
            ]);
        }

        $this->logger->info('MessageProcessor status', [
            'status' => $status,
            'message' => $message,
            'metadata' => $metadata
        ]);
    }
}

