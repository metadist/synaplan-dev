<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\Service\Message\MessageProcessor;
use App\Service\RateLimitService;
use App\Service\WhatsAppService;
use App\Service\EmailChannelService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Webhook Controller for External Channels
 * 
 * Handles incoming messages from:
 * - Email
 * - WhatsApp
 * - Other external services
 * 
 * Authentication: API Key (X-API-Key header or ?api_key query param)
 */
#[Route('/api/v1/webhooks', name: 'api_webhooks_')]
class WebhookController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageProcessor $messageProcessor,
        private RateLimitService $rateLimitService,
        private WhatsAppService $whatsAppService,
        private EmailChannelService $emailChannelService,
        private LoggerInterface $logger,
        private string $whatsappWebhookVerifyToken
    ) {}

    /**
     * Email Webhook
     * 
     * POST /api/v1/webhooks/email
     * 
     * Handles emails sent to:
     * - smart@synaplan.com (general)
     * - smart+keyword@synaplan.com (specific chat context)
     * 
     * Body:
     * {
     *   "from": "user@example.com",
     *   "to": "smart@synaplan.com" or "smart+keyword@synaplan.com",
     *   "subject": "Hello",
     *   "body": "Message text",
     *   "message_id": "external-msg-123",
     *   "in_reply_to": "previous-msg-id",
     *   "attachments": [...]
     * }
     */
    #[Route('/email', name: 'email', methods: ['POST'])]
    public function email(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return $this->json([
                'success' => false,
                'error' => 'Invalid JSON payload'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Validate required fields
        if (empty($data['from']) || empty($data['to']) || empty($data['body'])) {
            return $this->json([
                'success' => false,
                'error' => 'Missing required fields: from, to, body'
            ], Response::HTTP_BAD_REQUEST);
        }

        $fromEmail = $data['from'];
        $toEmail = $data['to'];
        $subject = $data['subject'] ?? '(no subject)';
        $body = $data['body'];
        $messageId = $data['message_id'] ?? null;
        $inReplyTo = $data['in_reply_to'] ?? null;

        // Parse keyword from to-address (smart+keyword@synaplan.com)
        $keyword = $this->emailChannelService->parseEmailKeyword($toEmail);

        $this->logger->info('Email webhook received', [
            'from' => $fromEmail,
            'to' => $toEmail,
            'keyword' => $keyword,
            'subject' => $subject,
            'body_length' => strlen($body)
        ]);

        // Find or create user from email
        $userResult = $this->emailChannelService->findOrCreateUserFromEmail($fromEmail);

        if ($userResult['blacklisted']) {
            $this->logger->warning('Blacklisted email attempted to send message', [
                'email' => $fromEmail
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Email address is blocked'
            ], Response::HTTP_FORBIDDEN);
        }

        $user = $userResult['user'];

        // Check rate limit (unified across all sources)
        $rateLimitCheck = $this->rateLimitService->checkLimit($user, 'MESSAGES');
        if (!$rateLimitCheck['allowed']) {
            return $this->json([
                'success' => false,
                'error' => 'Rate limit exceeded',
                'limit' => $rateLimitCheck['limit'],
                'used' => $rateLimitCheck['used'],
                'reset_at' => $rateLimitCheck['reset_at'] ?? null
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            // Find or create chat context
            $chat = $this->emailChannelService->findOrCreateChatContext(
                $user,
                $keyword,
                $subject,
                $inReplyTo
            );

            // Create incoming message
            $message = new Message();
            $message->setUserId($user->getId());
            $message->setChatId($chat->getId());
            $message->setTrackingId(time());
            $message->setProviderIndex('EMAIL');
            $message->setUnixTimestamp(time());
            $message->setDateTime(date('YmdHis'));
            $message->setMessageType('EMAIL');
            $message->setFile(0);
            $message->setTopic('CHAT');
            $message->setLanguage('en'); // Will be detected by classifier
            
            // Use subject as context if provided
            $messageText = $body;
            if (!empty($subject) && $subject !== '(no subject)') {
                $messageText = "Subject: " . $subject . "\n\n" . $messageText;
            }
            
            $message->setText($messageText);
            $message->setDirection('IN');
            $message->setStatus('processing');

            $this->em->persist($message);
            $this->em->flush(); // MUST flush before setMeta() to get message ID
            
            // Store email metadata
            $message->setMeta('channel', 'email');
            $message->setMeta('from_email', $fromEmail);
            $message->setMeta('to_email', $toEmail);
            
            if ($keyword) {
                $message->setMeta('email_keyword', $keyword);
            }
            if (!empty($subject)) {
                $message->setMeta('email_subject', $subject);
            }
            if ($messageId) {
                $message->setMeta('external_id', $messageId);
                // Store for email threading
                $chatData = $chat->getChatData();
                $chatData['email_message_id'] = $messageId;
                $chat->setChatData($chatData);
                $this->em->flush();
            }
            if (!empty($data['attachments'])) {
                $message->setMeta('has_attachments', 'true');
            }
            $this->em->flush(); // Flush metadata

            // Record usage (unified across all sources)
            $this->rateLimitService->recordUsage($user, 'MESSAGES');

            // Process message through pipeline
            $result = $this->messageProcessor->process($message);

            if (!$result['success']) {
                return $this->json([
                    'success' => false,
                    'error' => 'Message processing failed',
                    'details' => $result['error'] ?? 'Unknown error'
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $aiResponse = $result['response'];
            $responseText = $aiResponse['content'] ?? '';

            // TODO: Send email response back to user
            // This requires SMTP configuration
            // For now, we just return the response in JSON
            // A background job should send the actual email

            return $this->json([
                'success' => true,
                'message_id' => $message->getId(),
                'chat_id' => $chat->getId(),
                'response' => [
                    'text' => $responseText,
                    'metadata' => $aiResponse['metadata'] ?? []
                ],
                'user_info' => [
                    'is_anonymous' => $userResult['is_anonymous'] ?? false,
                    'rate_limit_level' => $user->getRateLimitLevel()
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Email webhook processing failed', [
                'from' => $fromEmail ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Internal error processing email'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * WhatsApp Webhook Verification (GET)
     * 
     * GET /api/v1/webhooks/whatsapp
     * 
     * Meta requires webhook verification with challenge
     */
    #[Route('/whatsapp', name: 'whatsapp_verify', methods: ['GET'])]
    public function whatsappVerify(Request $request): Response
    {
        $mode = $request->query->get('hub_mode');
        $token = $request->query->get('hub_verify_token');
        $challenge = $request->query->get('hub_challenge');

        if ($mode === 'subscribe' && $token === $this->whatsappWebhookVerifyToken) {
            $this->logger->info('WhatsApp webhook verified');
            return new Response($challenge, Response::HTTP_OK, [
                'Content-Type' => 'text/plain'
            ]);
        }

        $this->logger->warning('WhatsApp webhook verification failed', [
            'mode' => $mode,
            'token_match' => $token === $this->whatsappWebhookVerifyToken
        ]);

        return new Response('Forbidden', Response::HTTP_FORBIDDEN);
    }

    /**
     * WhatsApp Webhook (POST)
     * 
     * POST /api/v1/webhooks/whatsapp
     * 
     * Receives messages from Meta WhatsApp Business API
     * https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks/payload-examples
     */
    #[Route('/whatsapp', name: 'whatsapp', methods: ['POST'])]
    public function whatsapp(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (!$data || !isset($data['entry'])) {
            return $this->json([
                'success' => false,
                'error' => 'Invalid WhatsApp webhook payload'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $responses = [];

            // Process all entries
            foreach ($data['entry'] as $entry) {
                foreach ($entry['changes'] as $change) {
                    if ($change['field'] !== 'messages') {
                        continue;
                    }

                    $value = $change['value'];
                    
                    // Skip status updates
                    if (empty($value['messages'])) {
                        continue;
                    }

                    foreach ($value['messages'] as $incomingMsg) {
                        $responses[] = $this->processWhatsAppMessage($incomingMsg, $value, $user);
                    }
                }
            }

            return $this->json([
                'success' => true,
                'processed' => count($responses),
                'responses' => $responses
            ]);

        } catch (\Exception $e) {
            $this->logger->error('WhatsApp webhook processing failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Internal error processing WhatsApp message'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Process single WhatsApp message
     */
    private function processWhatsAppMessage(array $incomingMsg, array $value, User $user): array
    {
        $from = $incomingMsg['from'];
        $messageId = $incomingMsg['id'];
        $timestamp = (int) $incomingMsg['timestamp'];
        $type = $incomingMsg['type'];

        $this->logger->info('WhatsApp message received', [
            'user_id' => $user->getId(),
            'from' => $from,
            'type' => $type,
            'message_id' => $messageId
        ]);

        // Check rate limit
        $rateLimitCheck = $this->rateLimitService->checkLimit($user, 'MESSAGES');
        if (!$rateLimitCheck['allowed']) {
            return [
                'success' => false,
                'message_id' => $messageId,
                'error' => 'Rate limit exceeded'
            ];
        }

        // Extract message text
        $messageText = '';
        $mediaId = null;
        $mediaUrl = null;

        switch ($type) {
            case 'text':
                $messageText = $incomingMsg['text']['body'];
                break;
            case 'image':
                $mediaId = $incomingMsg['image']['id'];
                $mediaUrl = $incomingMsg['image']['link'] ?? null;
                $messageText = $incomingMsg['image']['caption'] ?? '[Image]';
                break;
            case 'audio':
                $mediaId = $incomingMsg['audio']['id'];
                $messageText = '[Audio message]';
                break;
            case 'video':
                $mediaId = $incomingMsg['video']['id'];
                $messageText = $incomingMsg['video']['caption'] ?? '[Video]';
                break;
            case 'document':
                $mediaId = $incomingMsg['document']['id'];
                $messageText = $incomingMsg['document']['caption'] ?? '[Document]';
                break;
            default:
                $messageText = "[Unsupported message type: $type]";
        }

        // Create incoming message
        $message = new Message();
        $message->setUserId($user->getId());
        $message->setTrackingId($timestamp);
        $message->setProviderIndex('WHATSAPP');
        $message->setUnixTimestamp($timestamp);
        $message->setDateTime(date('YmdHis', $timestamp));
        $message->setMessageType('WHATSAPP');
        $message->setFile(0); // Will be set by preprocessor if media
        $message->setTopic('CHAT');
        $message->setLanguage('en'); // Will be detected
        $message->setText($messageText);
        $message->setDirection('IN');
        $message->setStatus('processing');

        $this->em->persist($message);
        $this->em->flush(); // MUST flush before setMeta() to get message ID
        
        // Store WhatsApp metadata
        $message->setMeta('channel', 'whatsapp');
        $message->setMeta('from_phone', $from);
        $message->setMeta('external_id', $messageId);
        $message->setMeta('message_type', $type);
        
        if (!empty($value['contacts'][0]['profile']['name'])) {
            $message->setMeta('profile_name', $value['contacts'][0]['profile']['name']);
        }
        
        if ($mediaId) {
            $message->setMeta('media_id', $mediaId);
            
            // Get media URL from WhatsApp API if not provided
            if (!$mediaUrl) {
                $mediaUrl = $this->whatsAppService->getMediaUrl($mediaId);
            }
            
            if ($mediaUrl) {
                $message->setMeta('media_url', $mediaUrl);
            }
        }
        
        $this->em->flush(); // Flush metadata

        // Record usage
        $this->rateLimitService->recordUsage($user, 'MESSAGES');

        // Mark as read
        $this->whatsAppService->markAsRead($messageId);

        // Process message through pipeline (PreProcessor -> Classifier -> Processor)
        $result = $this->messageProcessor->process($message);

        if (!$result['success']) {
            return [
                'success' => false,
                'message_id' => $messageId,
                'error' => $result['error'] ?? 'Processing failed'
            ];
        }

        $response = $result['response'];
        $responseText = $response['content'] ?? '';

        // Send response back to WhatsApp
        if (!empty($responseText)) {
            $sendResult = $this->whatsAppService->sendMessage($from, $responseText);
            
            if ($sendResult['success']) {
                // Store outgoing message
                $outgoingMessage = new Message();
                $outgoingMessage->setUserId($user->getId());
                $outgoingMessage->setTrackingId(time());
                $outgoingMessage->setProviderIndex('WHATSAPP');
                $outgoingMessage->setUnixTimestamp(time());
                $outgoingMessage->setDateTime(date('YmdHis'));
                $outgoingMessage->setMessageType('WHATSAPP');
                $outgoingMessage->setFile(0);
                $outgoingMessage->setTopic('CHAT');
                $outgoingMessage->setLanguage('en');
                $outgoingMessage->setText($responseText);
                $outgoingMessage->setDirection('OUT');
                $outgoingMessage->setStatus('sent');
                
                $this->em->persist($outgoingMessage);
                $this->em->flush();
                
                $outgoingMessage->setMeta('channel', 'whatsapp');
                $outgoingMessage->setMeta('to_phone', $from);
                $outgoingMessage->setMeta('external_id', $sendResult['message_id']);
                $this->em->flush();
            }
        }

        return [
            'success' => true,
            'message_id' => $messageId,
            'response_sent' => !empty($responseText)
        ];
    }

    /**
     * Generic Webhook for other channels
     * 
     * POST /api/v1/webhooks/generic
     */
    #[Route('/generic', name: 'generic', methods: ['POST'])]
    public function generic(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (!$data || empty($data['message'])) {
            return $this->json([
                'success' => false,
                'error' => 'Missing required field: message'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Check rate limit
        $rateLimitCheck = $this->rateLimitService->checkLimit($user, 'MESSAGES');
        if (!$rateLimitCheck['allowed']) {
            return $this->json([
                'success' => false,
                'error' => 'Rate limit exceeded',
                'limit' => $rateLimitCheck['limit'],
                'used' => $rateLimitCheck['used'],
                'reset_at' => $rateLimitCheck['reset_at'] ?? null
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $message = new Message();
            $message->setUserId($user->getId());
            $message->setTrackingId(time());
            $message->setProviderIndex($data['channel'] ?? 'API');
            $message->setUnixTimestamp(time());
            $message->setDateTime(date('YmdHis'));
            $message->setMessageType('API');
            $message->setFile(0);
            $message->setTopic('CHAT');
            $message->setLanguage('en');
            $message->setText($data['message']);
            $message->setDirection('IN');
            $message->setStatus('processing');

            $this->em->persist($message);
            $this->em->flush(); // MUST flush before setMeta() to get message ID
            
            // Store custom metadata if provided
            if (!empty($data['metadata']) && is_array($data['metadata'])) {
                foreach ($data['metadata'] as $key => $value) {
                    if (is_string($value)) {
                        $message->setMeta($key, $value);
                    }
                }
            }
            $this->em->flush(); // Flush metadata

            // Record usage
            $this->rateLimitService->recordUsage($user, 'MESSAGES');

            $result = $this->messageProcessor->process($message);

            if (!$result['success']) {
                return $this->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Processing failed'
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $response = $result['response'];

            return $this->json([
                'success' => true,
                'message_id' => $message->getId(),
                'response' => [
                    'text' => $response['content'] ?? '',
                    'metadata' => $response['metadata'] ?? []
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Generic webhook failed', [
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Internal error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

