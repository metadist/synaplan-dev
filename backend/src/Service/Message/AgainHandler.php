<?php

namespace App\Service\Message;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Entity\MessageMeta;
use App\Entity\User;
use App\Service\AgainService;
use App\Service\ModelConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * AgainHandler
 * 
 * Handles "Again" requests where a user wants to re-process
 * their message with a different AI model.
 * 
 * This keeps the MessageController clean and separates concerns.
 */
class AgainHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private AiFacade $aiFacade,
        private AgainService $againService,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger
    ) {}

    /**
     * Process an "Again" request
     * 
     * @param User $user Current user
     * @param array $data Request data with originalMessageId, modelId, promptId
     * @return array Response data
     * @throws \Exception On processing errors
     */
    public function processAgainRequest(User $user, array $data): array
    {
        $originalMessageId = $data['originalMessageId'] ?? null;
        $modelId = $data['modelId'] ?? null;
        $promptId = $data['promptId'] ?? null;

        if (!$originalMessageId) {
            throw new \InvalidArgumentException('Original message ID is required');
        }

        // Get original incoming message
        $originalMessage = $this->em->getRepository(Message::class)->find($originalMessageId);
        
        if (!$originalMessage || $originalMessage->getUserId() !== $user->getId()) {
            throw new \RuntimeException('Original message not found or access denied');
        }

        // Create new incoming message with same content
        $incomingMessage = $this->createIncomingMessage($user, $originalMessage);
        
        $this->em->persist($incomingMessage);
        $this->em->flush();
        
        $this->logger->info('AgainHandler: Message persisted and flushed', [
            'message_id' => $incomingMessage->getId(),
            'has_id' => $incomingMessage->getId() !== null
        ]);

        // Set metadata for skipping sorting
        $this->setMessageMetadata($incomingMessage, $promptId, $modelId);

        // Resolve model_id to provider + model name
        $provider = null;
        $modelName = null;
        if ($modelId) {
            $provider = $this->modelConfigService->getProviderForModel($modelId);
            $modelName = $this->modelConfigService->getModelName($modelId);
            
            $this->logger->info('AgainHandler: Resolved model', [
                'model_id' => $modelId,
                'provider' => $provider,
                'model' => $modelName
            ]);
        }

        // Process with AI
        $aiResponse = $this->aiFacade->chat(
            [['role' => 'user', 'content' => $incomingMessage->getText()]],
            $user->getId(),
            [
                'provider' => $provider,
                'model' => $modelName
            ]
        );

        // Create outgoing message
        $outgoingMessage = $this->createOutgoingMessage(
            $user,
            $incomingMessage,
            $aiResponse
        );

        $this->em->persist($outgoingMessage);
        $incomingMessage->setStatus('complete');
        $this->em->flush();

        $this->logger->info('Again request processed', [
            'user_id' => $user->getId(),
            'original_message_id' => $originalMessageId,
            'new_message_id' => $outgoingMessage->getId(),
            'model_id' => $modelId,
        ]);

        // Get Again models for next iteration
        $againData = $this->getAgainData($incomingMessage->getTopic(), $modelId);

        return [
            'success' => true,
            'message' => [
                'id' => $outgoingMessage->getId(),
                'text' => $outgoingMessage->getText(),
                'hasFile' => (bool) $outgoingMessage->getFile(),
                'filePath' => $outgoingMessage->getFilePath(),
                'fileType' => $outgoingMessage->getFileType(),
                'provider' => $outgoingMessage->getProviderIndex(),
                'timestamp' => $outgoingMessage->getUnixTimestamp(),
                'trackId' => $outgoingMessage->getTrackingId(),
                'topic' => $incomingMessage->getTopic(),
            ],
            'again' => $againData
        ];
    }

    /**
     * Create incoming message clone
     */
    private function createIncomingMessage(User $user, Message $originalMessage): Message
    {
        $message = new Message();
        $message->setUserId($user->getId());
        $message->setTrackingId($originalMessage->getTrackingId());
        $message->setProviderIndex('WEB');
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('YmdHis'));
        $message->setMessageType('WEB');
        $message->setFile($originalMessage->getFile());
        $message->setFilePath($originalMessage->getFilePath());
        $message->setFileType($originalMessage->getFileType());
        $message->setTopic($originalMessage->getTopic());
        $message->setLanguage($originalMessage->getLanguage());
        $message->setText($originalMessage->getText());
        $message->setDirection('IN');
        $message->setStatus('processing');

        return $message;
    }

    /**
     * Set message metadata to skip sorting
     */
    private function setMessageMetadata(Message $message, ?string $promptId, ?int $modelId): void
    {
        $this->logger->info('AgainHandler: setMessageMetadata called', [
            'message_id' => $message->getId(),
            'has_id' => $message->getId() !== null,
            'prompt_id' => $promptId,
            'model_id' => $modelId
        ]);
        
        // Message must be flushed before creating MessageMeta (needs message ID)
        if (!$message->getId()) {
            $this->logger->error('AgainHandler: Message has no ID!', [
                'message' => $message
            ]);
            throw new \LogicException('Message must be persisted and flushed before setting metadata');
        }

        // Set PROMPTID in MessageMeta to skip sorting
        if ($promptId) {
            $meta = new MessageMeta();
            $meta->setMessage($message); // Use setMessage() instead of setMessageId()
            $meta->setMetaKey('PROMPTID');
            $meta->setMetaValue($promptId);
            $this->em->persist($meta);
            $this->logger->info('AgainHandler: PROMPTID meta created');
        }

        // Set MODEL_ID in MessageMeta if specific model requested
        if ($modelId) {
            $meta = new MessageMeta();
            $meta->setMessage($message); // Use setMessage() instead of setMessageId()
            $meta->setMetaKey('MODEL_ID');
            $meta->setMetaValue((string) $modelId);
            $this->em->persist($meta);
            $this->logger->info('AgainHandler: MODEL_ID meta created');
        }

        $this->em->flush();
        $this->logger->info('AgainHandler: Metadata flushed');
    }

    /**
     * Create outgoing message from AI response
     */
    private function createOutgoingMessage(User $user, Message $incomingMessage, array $aiResponse): Message
    {
        $responseText = $aiResponse['content'] ?? 'No response';
        $responseProvider = $aiResponse['provider'] ?? 'test';

        // Parse for media markers
        [$hasFile, $filePath, $fileType, $cleanText] = $this->parseMediaMarkers($responseText);

        $message = new Message();
        $message->setUserId($user->getId());
        $message->setTrackingId($incomingMessage->getTrackingId());
        $message->setProviderIndex($responseProvider);
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('YmdHis'));
        $message->setMessageType('WEB');
        $message->setFile($hasFile ? 1 : 0);
        $message->setFilePath($filePath);
        $message->setFileType($fileType);
        $message->setTopic($incomingMessage->getTopic());
        $message->setLanguage($incomingMessage->getLanguage());
        $message->setText(trim($cleanText));
        $message->setDirection('OUT');
        $message->setStatus('complete');

        return $message;
    }

    /**
     * Parse media markers like [IMAGE:url] or [VIDEO:url]
     */
    private function parseMediaMarkers(string $text): array
    {
        $hasFile = false;
        $filePath = '';
        $fileType = '';
        $cleanText = $text;

        if (preg_match('/\[IMAGE:(.*?)]/i', $text, $matches)) {
            $filePath = $matches[1];
            $fileType = 'png';
            $hasFile = true;
            $cleanText = str_replace($matches[0], '', $text);
        } elseif (preg_match('/\[VIDEO:(.*?)]/i', $text, $matches)) {
            $filePath = $matches[1];
            $fileType = 'mp4';
            $hasFile = true;
            $cleanText = str_replace($matches[0], '', $text);
        }

        return [$hasFile, $filePath, $fileType, $cleanText];
    }

    /**
     * Get Again data for response
     */
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

