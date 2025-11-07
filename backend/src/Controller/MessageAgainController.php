<?php

namespace App\Controller;

use App\Repository\MessageRepository;
use App\Repository\ModelRepository;
use App\Service\ModelConfigService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use App\Entity\User;

#[Route('/api/v1/messages', name: 'api_messages_')]
#[OA\Tag(name: 'Messages')]
class MessageAgainController extends AbstractController
{
    public function __construct(
        private MessageRepository $messageRepository,
        private ModelRepository $modelRepository,
        private ModelConfigService $modelConfigService
    ) {}

    #[Route('/{messageId}/again-options', name: 'again_options', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/messages/{messageId}/again-options',
        summary: 'Get eligible models for "Again" functionality',
        description: 'Returns available models to regenerate a message with different AI models',
        security: [['Bearer' => []]],
        tags: ['Messages']
    )]
    #[OA\Parameter(
        name: 'messageId',
        in: 'path',
        required: true,
        description: 'Message ID to get again options for',
        schema: new OA\Schema(type: 'integer', example: 123)
    )]
    #[OA\Response(
        response: 200,
        description: 'List of eligible models',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'models',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'integer'),
                            new OA\Property(property: 'service', type: 'string', example: 'openai'),
                            new OA\Property(property: 'name', type: 'string', example: 'gpt-4'),
                            new OA\Property(property: 'providerId', type: 'string', example: 'openai'),
                            new OA\Property(property: 'description', type: 'string'),
                            new OA\Property(property: 'quality', type: 'integer'),
                            new OA\Property(property: 'rating', type: 'number', format: 'float'),
                            new OA\Property(property: 'tag', type: 'string', example: 'CHAT'),
                            new OA\Property(property: 'label', type: 'string', example: 'gpt-4 (OpenAI)')
                        ]
                    )
                ),
                new OA\Property(property: 'predicted', type: 'integer', nullable: true, description: 'Predicted best next model ID')
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 403, description: 'Access denied - not message owner')]
    #[OA\Response(response: 404, description: 'Message not found')]
    public function getAgainOptions(
        int $messageId,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Find message
        $message = $this->messageRepository->find($messageId);
        
        if (!$message) {
            return $this->json(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        // Verify ownership
        if ($message->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        // Get message topic/classification
        $topic = $message->getTopic();
        $capability = $this->mapTopicToCapability($topic);

        // Get all active models with this capability, sorted by quality and rating (DESC)
        $models = $this->modelRepository->createQueryBuilder('m')
            ->where('m.active = 1')
            ->andWhere('m.tag = :capability')
            ->setParameter('capability', $capability)
            ->orderBy('m.quality', 'DESC')  // Higher quality first
            ->addOrderBy('m.rating', 'DESC') // Then higher rating
            ->addOrderBy('m.name', 'ASC')    // Finally alphabetically for consistency
            ->getQuery()
            ->getResult();

        // Get current model ID to exclude it from the list
        $currentModelId = $this->getCurrentModelId($message);

        // Format models for frontend
        $eligibleModels = [];
        foreach ($models as $model) {
            $eligibleModels[] = [
                'id' => $model->getId(),
                'service' => $model->getService(),
                'name' => $model->getName(),
                'providerId' => $model->getProviderId(),
                'description' => $model->getDescription(),
                'quality' => $model->getQuality(),
                'rating' => $model->getRating(),
                'tag' => strtoupper($model->getTag()),
                'label' => $this->formatModelLabel($model)
            ];
        }

        // Predicted next: highest ranked model that's DIFFERENT from current (Round-Robin)
        $predictedNext = null;
        
        foreach ($eligibleModels as $model) {
            if ($model['id'] !== $currentModelId) {
                $predictedNext = $model;
                break;
            }
        }

        // If no different model found (e.g., only one model available), use first
        if (!$predictedNext && count($eligibleModels) > 0) {
            $predictedNext = $eligibleModels[0];
        }

        return $this->json([
            'success' => true,
            'message_id' => $messageId,
            'topic' => $topic,
            'capability' => $capability,
            'eligible_models' => $eligibleModels,
            'predicted_next' => $predictedNext,
            'current_model_id' => $currentModelId
        ]);
    }

    private function mapTopicToCapability(string $topic): string
    {
        return match(strtolower($topic)) {
            'mediamaker' => 'text2pic',
            'analyzefile' => 'analyze',
            'general', 'chat' => 'chat',
            default => 'chat'
        };
    }

    private function formatModelLabel(object $model): string
    {
        $name = $model->getName();
        $service = $model->getService();
        
        // Format: "Service: Model Name"
        return "{$service}: {$name}";
    }

    private function getCurrentModelId($message): ?int
    {
        // Try to get from message metadata (newly stored)
        $modelId = $message->getMeta('ai_chat_model_id');
        
        if ($modelId) {
            return (int)$modelId;
        }
        
        // Fallback: null if not stored
        return null;
    }
}

