<?php

namespace App\Service;

use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Service für dynamische AI-Modell-Konfiguration basierend auf User-Einstellungen
 * 
 * Ermöglicht User-spezifische Default-Modelle aus BCONFIG + BMODELS Tabellen
 */
class ModelConfigService
{
    public function __construct(
        private ConfigRepository $configRepository,
        private ModelRepository $modelRepository,
        private CacheItemPoolInterface $cache
    ) {}

    /**
     * Holt Default-Provider für einen User und Capability
     * 
     * Reihenfolge:
     * 1. User-spezifische Config (BCONFIG: BOWNERID=userId, BGROUP='ai', BSETTING='default_chat_provider')
     * 2. Global Default Config (BOWNERID=0)
     * 3. Fallback: 'test'
     */
    public function getDefaultProvider(?int $userId, string $capability = 'chat'): string
    {
        $cacheKey = "model_config.provider.{$userId}.{$capability}";
        $item = $this->cache->getItem($cacheKey);
        
        if ($item->isHit()) {
            return $item->get();
        }
        
        // 1. User-spezifische Config
        if ($userId) {
            $config = $this->configRepository->findByOwnerGroupAndSetting(
                $userId,
                'ai',
                "default_{$capability}_provider"
            );
            
            if ($config) {
                $provider = $config->getValue();
                $item->set($provider);
                $item->expiresAfter(300); // 5 Min Cache
                $this->cache->save($item);
                return $provider;
            }
        }
        
        // 2. Global Default (ownerId = 0)
        $config = $this->configRepository->findByOwnerGroupAndSetting(
            0,
            'ai',
            "default_{$capability}_provider"
        );
        
        if ($config) {
            $provider = $config->getValue();
            $item->set($provider);
            $item->expiresAfter(300);
            $this->cache->save($item);
            return $provider;
        }
        
        // 3. Fallback
        $fallback = 'test';
        $item->set($fallback);
        $item->expiresAfter(60);
        $this->cache->save($item);
        return $fallback;
    }

    /**
     * Holt Default-Modell für einen User, Provider und Capability (OLD METHOD - DEPRECATED)
     * 
     * Reihenfolge:
     * 1. User-spezifische Config (BCONFIG: 'default_chat_model')
     * 2. BMODELS Tabelle (BPROVIDER, BCAPABILITY, BISDEFAULT=1)
     * 3. ENV Variable (fallback)
     */
    public function getDefaultModelOld(?int $userId, string $provider, string $capability = 'chat'): ?string
    {
        $cacheKey = "model_config.model.{$userId}.{$provider}.{$capability}";
        $item = $this->cache->getItem($cacheKey);
        
        if ($item->isHit()) {
            return $item->get();
        }
        
        // 1. User-spezifische Config
        if ($userId) {
            $config = $this->configRepository->findByOwnerGroupAndSetting(
                $userId,
                'ai',
                "default_{$capability}_model"
            );
            
            if ($config) {
                $model = $config->getValue();
                $item->set($model);
                $item->expiresAfter(300);
                $this->cache->save($item);
                return $model;
            }
        }
        
        // 2. BMODELS Tabelle
        $model = $this->modelRepository->findDefaultByProviderAndCapability($provider, $capability);
        
        if ($model) {
            $modelName = $model->getName();
            $item->set($modelName);
            $item->expiresAfter(300);
            $this->cache->save($item);
            return $modelName;
        }
        
        // 3. null zurückgeben - Provider nutzt dann seinen eigenen Default
        $item->set(null);
        $item->expiresAfter(60);
        $this->cache->save($item);
        return null;
    }

    /**
     * Setzt User-spezifischen Default-Provider
     */
    public function setDefaultProvider(int $userId, string $capability, string $provider): void
    {
        $config = $this->configRepository->findByOwnerGroupAndSetting(
            $userId,
            'ai',
            "default_{$capability}_provider"
        );
        
        if (!$config) {
            $config = new \App\Entity\Config();
            $config->setOwnerId($userId);
            $config->setGroup('ai');
            $config->setSetting("default_{$capability}_provider");
        }
        
        $config->setValue($provider);
        $this->configRepository->save($config);
        
        // Clear Cache
        $this->cache->deleteItem("model_config.provider.{$userId}.{$capability}");
    }

    /**
     * Setzt User-spezifisches Default-Modell
     */
    public function setDefaultModel(int $userId, string $capability, string $model): void
    {
        $config = $this->configRepository->findByOwnerGroupAndSetting(
            $userId,
            'ai',
            "default_{$capability}_model"
        );
        
        if (!$config) {
            $config = new \App\Entity\Config();
            $config->setOwnerId($userId);
            $config->setGroup('ai');
            $config->setSetting("default_{$capability}_model");
        }
        
        $config->setValue($model);
        $this->configRepository->save($config);
        
        // Clear Cache
        $cacheKeys = [
            "model_config.model.{$userId}.*.{$capability}",
        ];
        
        // TODO: Implement cache tag-based invalidation
        $this->cache->clear();
    }

    /**
     * Holt komplette AI-Config für einen User
     */
    public function getUserAiConfig(?int $userId): array
    {
        return [
            'chat' => [
                'provider' => $this->getDefaultProvider($userId, 'chat'),
                'model' => $this->getDefaultModel($userId, $this->getDefaultProvider($userId, 'chat'), 'chat'),
            ],
            'vision' => [
                'provider' => $this->getDefaultProvider($userId, 'vision'),
                'model' => $this->getDefaultModel($userId, $this->getDefaultProvider($userId, 'vision'), 'vision'),
            ],
            'embedding' => [
                'provider' => $this->getDefaultProvider($userId, 'embedding'),
                'model' => $this->getDefaultModel($userId, $this->getDefaultProvider($userId, 'embedding'), 'embedding'),
            ],
        ];
    }

    /**
     * Get default model ID for a specific capability
     * 
     * Priority: User Config > Global Config > Fallback
     */
    public function getDefaultModel(string $capability, ?int $userId = null): ?int
    {
        // Normalize capability key
        $configKey = 'DEFAULTMODEL/' . strtoupper($capability);

        // Try user-specific config first
        if ($userId) {
            $config = $this->configRepository->findOneBy([
                'ownerId' => $userId,
                'group' => 'DEFAULTMODEL',
                'setting' => strtoupper($capability),
            ]);

            if ($config) {
                return (int) $config->getValue();
            }
        }

        // Fall back to global config
        $config = $this->configRepository->findOneBy([
            'ownerId' => 0,
            'group' => 'DEFAULTMODEL',
            'setting' => strtoupper($capability),
        ]);

        if ($config) {
            return (int) $config->getValue();
        }

        return null;
    }

    /**
     * Get provider name for a specific model ID
     * Returns provider name from BMODELS.BSERVICE (e.g., 'Ollama', 'OpenAI')
     */
    public function getProviderForModel(int $modelId): ?string
    {
        $model = $this->modelRepository->find($modelId);
        
        if (!$model) {
            return null;
        }

        return strtolower($model->getService());
    }

    /**
     * Get model name for AI provider
     * Returns the actual model identifier (BPROVID or BNAME)
     */
    public function getModelName(int $modelId): ?string
    {
        $model = $this->modelRepository->find($modelId);
        
        if (!$model) {
            return null;
        }

        // Use BPROVID if set, otherwise BNAME
        return $model->getProviderId() ?: $model->getName();
    }
}
