<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\Chat;
use App\Service\WidgetService;
use App\Service\WidgetSessionService;
use App\Service\Message\MessageProcessor;
use App\Service\RateLimitService;
use App\Repository\ChatRepository;
use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;

/**
 * Widget Public API Controller
 * 
 * PUBLIC endpoints (no JWT required) for chat widgets embedded on external websites
 */
#[Route('/api/v1/widget', name: 'api_widget_public_')]
#[OA\Tag(name: 'Widget (Public)')]
class WidgetPublicController extends AbstractController
{
    public function __construct(
        private WidgetService $widgetService,
        private WidgetSessionService $sessionService,
        private MessageProcessor $messageProcessor,
        private RateLimitService $rateLimitService,
        private ChatRepository $chatRepository,
        private MessageRepository $messageRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {}

    /**
     * Get widget configuration
     * 
     * PUBLIC endpoint - no authentication required
     */
    #[Route('/{widgetId}/config', name: 'config', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/widget/{widgetId}/config',
        summary: 'Get widget configuration (public)',
        tags: ['Widget (Public)']
    )]
    #[OA\Parameter(
        name: 'widgetId',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'string', example: 'wdg_abc123...')
    )]
    #[OA\Response(
        response: 200,
        description: 'Widget configuration',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'widgetId', type: 'string'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'config', type: 'object'),
                new OA\Property(property: 'isActive', type: 'boolean')
            ]
        )
    )]
    public function config(string $widgetId): JsonResponse
    {
        $widget = $this->widgetService->getWidgetById($widgetId);

        if (!$widget) {
            return $this->json([
                'error' => 'Widget not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $isActive = $this->widgetService->isWidgetActive($widget);

        if (!$isActive) {
            return $this->json([
                'error' => 'Widget is not active',
                'reason' => 'owner_limits_exceeded'
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json([
            'success' => true,
            'widgetId' => $widget->getWidgetId(),
            'name' => $widget->getName(),
            'config' => $widget->getConfig(),
            'isActive' => true
        ]);
    }

    /**
     * Send message to widget (synchronous response)
     * 
     * PUBLIC endpoint - no authentication required, session-based rate limiting
     */
    #[Route('/{widgetId}/message', name: 'message', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/widget/{widgetId}/message',
        summary: 'Send message to widget (public, streaming)',
        tags: ['Widget (Public)']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['sessionId', 'text'],
            properties: [
                new OA\Property(property: 'sessionId', type: 'string', example: 'sess_xyz123...'),
                new OA\Property(property: 'text', type: 'string', example: 'Hello, I need help!'),
                new OA\Property(property: 'chatId', type: 'integer', nullable: true),
                new OA\Property(property: 'files', type: 'array', items: new OA\Items(type: 'integer'))
            ]
        )
    )]
    public function message(string $widgetId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        // Immediate test to see if we reach here
        if ($data === null) {
            return $this->json([
                'error' => 'Invalid JSON in request body'
            ], Response::HTTP_BAD_REQUEST);
        }

        error_log('📥 Widget message request: ' . json_encode($data));

        if (empty($data['sessionId']) || empty($data['text'])) {
            error_log('❌ Missing fields - sessionId: ' . ($data['sessionId'] ?? 'NULL') . ', text: ' . ($data['text'] ?? 'NULL'));
            return $this->json([
                'error' => 'Missing required fields: sessionId, text'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Get widget
        $widget = $this->widgetService->getWidgetById($widgetId);
        if (!$widget) {
            error_log('❌ Widget not found: ' . $widgetId);
            return $this->json(['error' => 'Widget not found'], Response::HTTP_NOT_FOUND);
        }

        error_log('✅ Widget found: ' . $widget->getName());

        // Check if widget is active
        if (!$this->widgetService->isWidgetActive($widget)) {
            return $this->json([
                'error' => 'Widget is not active',
                'reason' => 'owner_limits_exceeded'
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // Get or create session
        $session = $this->sessionService->getOrCreateSession($widgetId, $data['sessionId']);

        // Check session limits
        $limitCheck = $this->sessionService->checkSessionLimit($session);
        if (!$limitCheck['allowed']) {
            return $this->json([
                'error' => 'Rate limit exceeded',
                'reason' => $limitCheck['reason'],
                'remaining' => $limitCheck['remaining'],
                'retryAfter' => $limitCheck['retry_after']
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Check owner's limits
        $ownerId = $widget->getOwnerId();
        if (!$ownerId) {
            return $this->json(['error' => 'Widget owner not found'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Get owner entity (we need it for the user entity)
        $owner = $this->em->getRepository(\App\Entity\User::class)->find($ownerId);
        if (!$owner) {
            return $this->json(['error' => 'Widget owner not found'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            // Resolve chat for this session
            $sessionChatId = $session->getChatId();
            $chat = null;

            if ($sessionChatId) {
                $chat = $this->chatRepository->find($sessionChatId);
                if (!$chat || $chat->getUserId() !== $owner->getId()) {
                    $chat = null;
                }
            }

            if (!$chat && !empty($data['chatId'])) {
                $chat = $this->chatRepository->find((int) $data['chatId']);
                if (!$chat || $chat->getUserId() !== $owner->getId()) {
                    $chat = null;
                }
            }

            if (!$chat) {
                $now = new \DateTimeImmutable();
                $chat = new Chat();
                $chat->setUserId($owner->getId());
                $sessionSuffix = substr($session->getSessionId(), -6);
                $chat->setTitle(sprintf('Widget: %s • %s', $widget->getName(), $sessionSuffix));
                $chat->setCreatedAt($now);
                $chat->setUpdatedAt($now);
                $this->em->persist($chat);
                $this->em->flush();

                $this->logger->info('Widget chat created', [
                    'widget_id' => $widget->getWidgetId(),
                    'chat_id' => $chat->getId(),
                    'session_id' => $session->getSessionId()
                ]);
            } else {
                $chat->updateTimestamp();
                $this->em->flush();
            }

            // Create incoming message
            $incomingMessage = new Message();
            $incomingMessage->setUserId($owner->getId());
            $incomingMessage->setChat($chat);
            $incomingMessage->setText($data['text']);
            $incomingMessage->setDirection('IN');
            $incomingMessage->setStatus('processing');
            $incomingMessage->setMessageType('WDGT');
            $incomingMessage->setTrackingId(time());
            $incomingMessage->setUnixTimestamp(time());
            $incomingMessage->setDateTime(date('YmdHis'));
            $incomingMessage->setProviderIndex(999); // Special provider index for widgets

            $this->em->persist($incomingMessage);
            $this->em->flush();

            // Increment session message count
            $this->sessionService->incrementMessageCount($session);
            $this->sessionService->attachChat($session, $chat);

            \set_time_limit(0);

            $result = $this->messageProcessor->process(
                $incomingMessage,
                [
                    'fixed_task_prompt' => $widget->getTaskPromptTopic(),  // Direkt zum Task Prompt!
                    'skipSorting' => true,                                  // KEIN AI-Sorting!
                    'channel' => 'WIDGET',
                    'language' => 'en'
                ]
            );

            if (!($result['success'] ?? false)) {
                $errorMessage = $result['error'] ?? 'Processing failed';
                $this->logger->error('Widget message processing returned error', [
                    'error' => $errorMessage,
                    'widget_id' => $widget->getWidgetId(),
                ]);

                return $this->json([
                    'error' => $errorMessage,
                    'details' => $result,
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $responseData = $result['response'] ?? [];
            $responseText = '';
            $responseMetadata = [];

            if (is_array($responseData)) {
                $responseText = $responseData['content'] ?? '';
                $responseMetadata = $responseData['metadata'] ?? [];
            } elseif (is_string($responseData)) {
                $responseText = $responseData;
            } else {
                $responseText = (string) $responseData;
            }

            $tokens = $responseMetadata['tokens'] ?? 0;
            if (is_array($tokens)) {
                $tokens = array_sum(array_map(static fn ($value) => is_numeric($value) ? (int) $value : 0, $tokens));
            }

            // Persist outgoing AI message for owner visibility & history restoration
            $outgoingMessage = new Message();
            $outgoingMessage->setUserId($owner->getId());
            $outgoingMessage->setChat($chat);
            $outgoingMessage->setTrackingId($incomingMessage->getTrackingId());
            $outgoingMessage->setProviderIndex($responseMetadata['provider'] ?? 'AI_WIDGET');
            $outgoingMessage->setUnixTimestamp(time());
            $outgoingMessage->setDateTime(date('YmdHis'));
            $outgoingMessage->setMessageType('WDGT');
            $outgoingMessage->setFile(0);
            $outgoingMessage->setFilePath('');
            $outgoingMessage->setFileType('');
            $outgoingMessage->setTopic($incomingMessage->getTopic());
            $outgoingMessage->setLanguage($incomingMessage->getLanguage());
            $outgoingMessage->setText($responseText);
            $outgoingMessage->setDirection('OUT');
            $outgoingMessage->setStatus('complete');

            $incomingMessage->setStatus('complete');

            $this->em->persist($outgoingMessage);
            $this->em->flush();

            $this->rateLimitService->recordUsage($owner, 'MESSAGES', [
                'provider' => $responseMetadata['provider'] ?? null,
                'model' => $responseMetadata['model'] ?? null,
                'tokens' => $tokens,
                'channel' => 'WIDGET'
            ]);

            return $this->json([
                'success' => true,
                'messageId' => $incomingMessage->getId(),
                'chatId' => $chat->getId(),
                'response' => $responseText,
                'metadata' => [
                    'response' => $responseMetadata,
                    'classification' => $result['classification'] ?? null,
                    'preprocessed' => $result['preprocessed'] ?? null,
                    'search_results' => $result['search_results'] ?? null,
                ],
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Widget message failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'widget_id' => $widgetId,
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            // DEBUG: Return detailed error (REMOVE IN PRODUCTION!)
            return $this->json([
                'error' => 'Failed to process message',
                'debug' => [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => explode("\n", $e->getTraceAsString())
                ]
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get conversation history for a widget session.
     *
     * Restores chats after page reloads (PUBLIC).
     */
    #[Route('/{widgetId}/history', name: 'history', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/widget/{widgetId}/history',
        summary: 'Get widget chat history for a session',
        tags: ['Widget (Public)']
    )]
    public function history(string $widgetId, Request $request): JsonResponse
    {
        $sessionId = $request->query->getString('sessionId');

        if ($sessionId === '') {
            return $this->json([
                'error' => 'sessionId is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        $widget = $this->widgetService->getWidgetById($widgetId);
        if (!$widget) {
            return $this->json([
                'error' => 'Widget not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $session = $this->sessionService->getSession($widgetId, $sessionId);
        if (!$session) {
            return $this->json([
                'success' => true,
                'chatId' => null,
                'messages' => [],
                'session' => [
                    'sessionId' => $sessionId,
                    'messageCount' => 0,
                    'lastMessage' => null
                ]
            ]);
        }

        $chatId = $session->getChatId();
        if (!$chatId) {
            return $this->json([
                'success' => true,
                'chatId' => null,
                'messages' => [],
                'session' => [
                    'sessionId' => $session->getSessionId(),
                    'messageCount' => $session->getMessageCount(),
                    'lastMessage' => $session->getLastMessage() ?: null
                ]
            ]);
        }

        $chat = $this->chatRepository->find($chatId);
        if (!$chat || $chat->getUserId() !== $widget->getOwnerId()) {
            return $this->json([
                'success' => true,
                'chatId' => null,
                'messages' => [],
                'session' => [
                    'sessionId' => $session->getSessionId(),
                    'messageCount' => $session->getMessageCount(),
                    'lastMessage' => $session->getLastMessage() ?: null
                ]
            ]);
        }

        $messages = $this->messageRepository->findChatHistory(
            $chat->getUserId(),
            $chat->getId(),
            50,
            20000
        );

        $history = array_map(static function (Message $message) {
            return [
                'id' => $message->getId(),
                'direction' => $message->getDirection(),
                'text' => $message->getText(),
                'timestamp' => $message->getUnixTimestamp(),
                'messageType' => $message->getMessageType(),
                'metadata' => [
                    'topic' => $message->getTopic(),
                    'language' => $message->getLanguage(),
                ]
            ];
        }, $messages);

        return $this->json([
            'success' => true,
            'chatId' => $chat->getId(),
            'messages' => $history,
            'session' => [
                'sessionId' => $session->getSessionId(),
                'messageCount' => $session->getMessageCount(),
                'lastMessage' => $session->getLastMessage() ?: null
            ]
        ]);
    }
}

