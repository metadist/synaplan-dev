<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\Chat;
use App\Service\WidgetService;
use App\Service\WidgetSessionService;
use App\Service\Message\MessageProcessor;
use App\Service\RateLimitService;
use App\Repository\ChatRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
     * Send message to widget (SSE Streaming)
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
    public function message(string $widgetId, Request $request): StreamedResponse|JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['sessionId']) || empty($data['text'])) {
            return $this->json([
                'error' => 'Missing required fields: sessionId, text'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Get widget
        $widget = $this->widgetService->getWidgetById($widgetId);
        if (!$widget) {
            return $this->json(['error' => 'Widget not found'], Response::HTTP_NOT_FOUND);
        }

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
        $owner = $widget->getOwner();
        if (!$owner) {
            return $this->json(['error' => 'Widget owner not found'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            // Get or create chat for this widget session
            $chatId = $data['chatId'] ?? null;
            if ($chatId) {
                $chat = $this->chatRepository->find($chatId);
                if (!$chat || $chat->getUserId() !== $owner->getId()) {
                    $chat = null;
                }
            }

            if (!$chat) {
                $chat = new Chat();
                $chat->setUserId($owner->getId());
                $chat->setTitle('Widget: ' . $widget->getName());
                $chat->setCreatedAt(time());
                $this->em->persist($chat);
                $this->em->flush();
            }

            // Create incoming message
            $incomingMessage = new Message();
            $incomingMessage->setUserId($owner->getId());
            $incomingMessage->setChat($chat);
            $incomingMessage->setText($data['text']);
            $incomingMessage->setDirection('IN');
            $incomingMessage->setStatus('processing');
            $incomingMessage->setMessageType('WIDGET');
            $incomingMessage->setTrackingId(time());
            $incomingMessage->setUnixTimestamp(time());
            $incomingMessage->setDateTime(date('YmdHis'));
            $incomingMessage->setProviderIndex(999); // Special provider index for widgets

            $this->em->persist($incomingMessage);
            $this->em->flush();

            // Increment session message count
            $this->sessionService->incrementMessageCount($session);

            // Stream response using MessageProcessor
            // WICHTIG: skipSorting = true, direkt zum Task Prompt!
            $response = new StreamedResponse();
            $response->headers->set('Content-Type', 'text/event-stream');
            $response->headers->set('Cache-Control', 'no-cache');
            $response->headers->set('X-Accel-Buffering', 'no');

            $response->setCallback(function () use ($incomingMessage, $widget, $chat) {
                $responseText = '';
                $chunkCount = 0;

                try {
                    $result = $this->messageProcessor->processStream(
                        $incomingMessage,
                        function ($chunk) use (&$responseText, &$chunkCount) {
                            if (is_array($chunk)) {
                                $content = $chunk['content'] ?? '';
                            } else {
                                $content = $chunk;
                            }

                            $responseText .= $content;

                            if (!empty($content)) {
                                echo "event: data\n";
                                echo 'data: ' . json_encode(['chunk' => $content]) . "\n\n";
                                ob_flush();
                                flush();
                            }

                            $chunkCount++;
                        },
                        [
                            'topic' => $widget->getTaskPromptTopic(),  // Direkt zum Task Prompt!
                            'skipSorting' => true,                     // KEIN AI-Sorting!
                            'channel' => 'WIDGET',
                            'language' => 'en'
                        ]
                    );

                    // Send completion event
                    echo "event: complete\n";
                    echo 'data: ' . json_encode([
                        'status' => 'complete',
                        'messageId' => $incomingMessage->getId(),
                        'chatId' => $chat->getId()
                    ]) . "\n\n";
                    ob_flush();
                    flush();

                } catch (\Exception $e) {
                    $this->logger->error('Widget message processing failed', [
                        'error' => $e->getMessage(),
                        'widget_id' => $widget->getWidgetId()
                    ]);

                    echo "event: error\n";
                    echo 'data: ' . json_encode(['error' => 'Processing failed']) . "\n\n";
                    ob_flush();
                    flush();
                }
            });

            return $response;

        } catch (\Exception $e) {
            $this->logger->error('Widget message failed', [
                'error' => $e->getMessage(),
                'widget_id' => $widgetId
            ]);

            return $this->json([
                'error' => 'Failed to process message'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

