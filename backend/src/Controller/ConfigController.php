<?php

namespace App\Controller;

use App\Entity\Config;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\AI\Service\ProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use App\Entity\User;

#[Route('/api/v1/config', name: 'api_config_')]
class ConfigController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ConfigRepository $configRepository,
        private ModelRepository $modelRepository,
        private ProviderRegistry $providerRegistry
    ) {}

    /**
     * Get all available models (all active models for all capabilities)
     * User can choose ANY model for ANY capability (cross-capability usage)
     */
    #[Route('/models', name: 'models_list', methods: ['GET'])]
    public function getModels(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Get all active models sorted by quality
        $models = $this->modelRepository->findBy(['active' => 1], ['quality' => 'DESC', 'rating' => 'DESC']);
        
        // All capabilities get ALL models (user can choose any model for any task)
        $modelList = [];
        foreach ($models as $model) {
            $modelList[] = [
                'id' => $model->getId(),
                'service' => $model->getService(),
                'name' => $model->getName(),
                'providerId' => $model->getProviderId(),
                'description' => $model->getDescription(),
                'quality' => $model->getQuality(),
                'rating' => $model->getRating(),
                'tag' => strtoupper($model->getTag()),
                'isSystemModel' => $model->isSystemModel(),
                'features' => $model->getFeatures()
            ];
        }
        
        // Return same list for all capabilities (cross-capability)
        $grouped = [
            'SORT' => $modelList,
            'CHAT' => $modelList,
            'VECTORIZE' => $modelList,
            'PIC2TEXT' => $modelList,
            'TEXT2PIC' => $modelList,
            'SOUND2TEXT' => $modelList,
            'TEXT2SOUND' => $modelList,
            'ANALYZE' => $modelList
        ];

        return $this->json([
            'success' => true,
            'models' => $grouped
        ]);
    }

    /**
     * Get current default model configuration for user
     */
    #[Route('/models/defaults', name: 'models_defaults', methods: ['GET'])]
    public function getDefaultModels(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $userId = $user->getId();
        $capabilities = ['SORT', 'CHAT', 'VECTORIZE', 'PIC2TEXT', 'TEXT2PIC', 'SOUND2TEXT', 'TEXT2SOUND', 'ANALYZE'];
        
        $defaults = [];

        foreach ($capabilities as $capability) {
            // Try user-specific config first
            $config = $this->configRepository->findOneBy([
                'ownerId' => $userId,
                'group' => 'DEFAULTMODEL',
                'setting' => $capability
            ]);

            // Fall back to global config
            if (!$config) {
                $config = $this->configRepository->findOneBy([
                    'ownerId' => 0,
                    'group' => 'DEFAULTMODEL',
                    'setting' => $capability
                ]);
            }

            $defaults[$capability] = $config ? (int) $config->getValue() : null;
        }

        return $this->json([
            'success' => true,
            'defaults' => $defaults
        ]);
    }

    /**
     * Save default model configuration for user
     */
    #[Route('/models/defaults', name: 'models_defaults_save', methods: ['POST'])]
    public function saveDefaultModels(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['defaults']) || !is_array($data['defaults'])) {
            return $this->json(['error' => 'Invalid data'], Response::HTTP_BAD_REQUEST);
        }

        $userId = $user->getId();
        $validCapabilities = ['SORT', 'CHAT', 'VECTORIZE', 'PIC2TEXT', 'TEXT2PIC', 'SOUND2TEXT', 'TEXT2SOUND', 'ANALYZE'];

        foreach ($data['defaults'] as $capability => $modelId) {
            if (!in_array($capability, $validCapabilities)) {
                continue;
            }

            // Validate model exists - allow any active model for any capability (cross-capability)
            $model = $this->modelRepository->find($modelId);
            if (!$model) {
                return $this->json([
                    'error' => "Model {$modelId} not found"
                ], Response::HTTP_BAD_REQUEST);
            }
            
            // Check if model is active
            if ($model->getActive() !== 1) {
                return $this->json([
                    'error' => "Model {$modelId} is not active"
                ], Response::HTTP_BAD_REQUEST);
            }

            // Check if user-specific config exists
            $config = $this->configRepository->findOneBy([
                'ownerId' => $userId,
                'group' => 'DEFAULTMODEL',
                'setting' => $capability
            ]);

            if (!$config) {
                // Create new user-specific config
                $config = new Config();
                $config->setOwnerId($userId);
                $config->setGroup('DEFAULTMODEL');
                $config->setSetting($capability);
            }

            $config->setValue((string) $modelId);
            $this->em->persist($config);
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Default models saved successfully'
        ]);
    }

    /**
     * Check if a model is available/ready to use
     * 
     * @param int $modelId Model ID to check
     * @return JsonResponse {available: bool, provider_type: string, message?: string, install_command?: string}
     */
    #[Route('/models/{modelId}/check', name: 'models_check', methods: ['GET'])]
    public function checkModelAvailability(
        int $modelId,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $model = $this->modelRepository->find($modelId);
        if (!$model) {
            return $this->json([
                'available' => false,
                'error' => 'Model not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $service = strtolower($model->getService());
        $providerType = 'unknown';
        $available = false;
        $message = null;
        $installCommand = null;
        $envVar = null;

        // Determine provider type and check availability
        if ($service === 'ollama') {
            $providerType = 'local';
            
            // Check if Ollama provider is available
            try {
                $provider = $this->providerRegistry->getChatProvider('ollama');
                
                // Check if the specific model exists
                $modelName = $model->getProviderId() ?: $model->getName();
                
                // Try to list available models
                $status = $provider->getStatus();
                if (!empty($status['healthy'])) {
                    // Model is available if Ollama is running
                    // We assume it's available; the user will get a proper error if not
                    $available = true;
                } else {
                    $message = "Ollama server is not running";
                }
                
                // Always provide install command for Ollama models
                $installCommand = "docker compose exec ollama ollama pull {$modelName}";
            } catch (\Exception $e) {
                $message = "Ollama not available: " . $e->getMessage();
            }
        } elseif (in_array($service, ['openai', 'anthropic', 'groq', 'gemini', 'google', 'mistral'])) {
            $providerType = 'external';
            
            // Check if API key is configured
            $envVarMap = [
                'openai' => 'OPENAI_API_KEY',
                'anthropic' => 'ANTHROPIC_API_KEY',
                'groq' => 'GROQ_API_KEY',
                'gemini' => 'GEMINI_API_KEY',
                'google' => 'GOOGLE_API_KEY',
                'mistral' => 'MISTRAL_API_KEY'
            ];
            
            $envVar = $envVarMap[$service] ?? null;
            
            if ($envVar) {
                // Check if env var is set and not empty
                $apiKey = $_ENV[$envVar] ?? '';
                $available = !empty($apiKey) && $apiKey !== 'your-api-key-here';
                
                if (!$available) {
                    $message = "API key not configured for {$service}";
                }
            }
        } else {
            // Unknown provider (e.g., test, custom)
            $available = true; // Assume available
        }

        $response = [
            'available' => $available,
            'provider_type' => $providerType,
            'model_name' => $model->getProviderId() ?: $model->getName(),
            'service' => $service
        ];

        if ($message) {
            $response['message'] = $message;
        }

        if ($installCommand) {
            $response['install_command'] = $installCommand;
        }

        if ($envVar) {
            $response['env_var'] = $envVar;
            $response['setup_instructions'] = "Add {$envVar}=your-api-key to your .env.local file";
        }

        return $this->json($response);
    }
}

