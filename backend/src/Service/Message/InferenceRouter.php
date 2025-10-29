<?php

namespace App\Service\Message;

use App\Entity\Message;
use App\Service\Message\Handler\ChatHandler;
use App\Service\Message\Handler\MediaGenerationHandler;
use App\Service\Message\Handler\CodeGenerationHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Router for Message Processing based on Intent/BTAG
 * 
 * Dispatched to:
 * - ChatHandler (normal Chat)
 * - MediaGenerationHandler (Images, Videos, Audio generation)
 * - CodeGenerationHandler (Code generation)
 * - ToolHandler (Email, Calendar, etc.)
 * - Other handlers...
 */
class InferenceRouter
{
    private array $handlers = [];

    public function __construct(
        #[TaggedIterator('app.message.handler')]
        iterable $handlers,
        private LoggerInterface $logger
    ) {
        foreach ($handlers as $handler) {
            $this->handlers[$handler->getName()] = $handler;
        }
    }

    /**
     * Routed Message zu richtigem Handler
     */
    public function route(Message $message, array $thread, array $classification, callable $progressCallback = null): array
    {
        $intent = $classification['intent'] ?? 'chat';
        
        $this->notify($progressCallback, 'processing', "Routing to handler: {$intent}");

        // Handler für Intent finden
        $handler = $this->getHandler($intent);

        try {
            $result = $handler->handle($message, $thread, $classification, $progressCallback);
            
            $this->notify($progressCallback, 'processing', "Handler complete: {$intent}");
            
            return $result;
        } catch (\Exception $e) {
            $this->logger->error("Handler failed: {$e->getMessage()}");
            
            // Fallback zu Chat Handler
            if ($intent !== 'chat') {
                $this->notify($progressCallback, 'processing', "Falling back to chat handler");
                return $this->handlers['chat']->handle($message, $thread, $classification, $progressCallback);
            }
            
            throw $e;
        }
    }

    /**
     * Routed Message zu richtigem Handler mit Streaming-Support
     */
    public function routeStream(
        Message $message, 
        array $thread, 
        array $classification, 
        callable $streamCallback,
        ?callable $progressCallback = null,
        array $options = []
    ): array {
        $intent = $classification['intent'] ?? 'chat';
        
        $this->logger->info('InferenceRouter: Routing to handler', [
            'intent' => $intent,
            'topic' => $classification['topic'] ?? 'unknown',
            'classification' => $classification
        ]);
        
        // DEBUG: Write to file
        file_put_contents('/tmp/sorting-debug.txt', 
            "=== ROUTER DEBUG ===\n" .
            "Time: " . date('Y-m-d H:i:s') . "\n" .
            "Intent: " . $intent . "\n" .
            "Topic: " . ($classification['topic'] ?? 'unknown') . "\n" .
            "Classification: " . json_encode($classification, JSON_PRETTY_PRINT) . "\n" .
            "===================\n\n",
            FILE_APPEND
        );
        
        $this->notify($progressCallback, 'processing', "Routing to handler: {$intent}");

        // Handler für Intent finden
        $handler = $this->getHandler($intent);
        
        // DEBUG: Write handler info
        file_put_contents('/tmp/sorting-debug.txt', 
            "=== HANDLER RESOLVED ===\n" .
            "Handler Name: " . $handler->getName() . "\n" .
            "Intent: " . $intent . "\n" .
            "===================\n\n",
            FILE_APPEND
        );
        
        $this->logger->info('InferenceRouter: Handler resolved', [
            'handler_name' => $handler->getName(),
            'intent' => $intent
        ]);

        try {
            $result = $handler->handleStream($message, $thread, $classification, $streamCallback, $progressCallback, $options);
            
            $this->notify($progressCallback, 'processing', "Handler complete: {$intent}");
            
            return $result;
        } catch (\Exception $e) {
            $this->logger->error("Handler streaming failed: {$e->getMessage()}");
            
            // Fallback zu Chat Handler
            if ($intent !== 'chat') {
                $this->notify($progressCallback, 'processing', "Falling back to chat handler");
                return $this->handlers['chat']->handleStream($message, $thread, $classification, $streamCallback, $progressCallback, $options);
            }
            
            throw $e;
        }
    }

    private function getHandler(string $intent): object
    {
        // Mapping von Intent zu Handler
        $handlerMap = [
            'chat' => 'chat',
            'image_generation' => 'image_generation',
            'code_generation' => 'code_generation',
            'summarize' => 'chat', // Nutzt Chat Handler mit speziellem Prompt
            'translate' => 'chat',
            'email' => 'tool',
            'calendar' => 'tool',
        ];

        $handlerName = $handlerMap[$intent] ?? 'chat';

        if (!isset($this->handlers[$handlerName])) {
            $this->logger->warning("Handler not found: {$handlerName}, falling back to chat");
            $handlerName = 'chat';
        }

        return $this->handlers[$handlerName];
    }

    private function notify(?callable $callback, string $status, string $message): void
    {
        if ($callback) {
            $callback([
                'status' => $status,
                'message' => $message,
                'timestamp' => time(),
            ]);
        }
    }
}

