<?php

namespace App\Tests\Unit;

use App\Service\AgainService;
use App\Repository\ModelRepository;
use App\Repository\ConfigRepository;
use App\Entity\Model;
use App\Entity\Config;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AgainServiceTest extends TestCase
{
    private ModelRepository $modelRepository;
    private ConfigRepository $configRepository;
    private LoggerInterface $logger;
    private AgainService $service;

    protected function setUp(): void
    {
        $this->modelRepository = $this->createMock(ModelRepository::class);
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new AgainService(
            $this->modelRepository,
            $this->configRepository,
            $this->logger
        );
    }

    public function testGetEligibleModelsReturnsFormattedModels(): void
    {
        $tag = 'chat';
        $userId = 1;

        $model1 = $this->createMockModel(1, 'OpenAI', 'GPT-4', 'chat', 'gpt-4', 10.0, 4.5);
        $model2 = $this->createMockModel(2, 'Anthropic', 'Claude-3', 'chat', 'claude-3', 9.0, 4.2);

        $this->configRepository
            ->method('findOneBy')
            ->willReturn(null); // No min rating config

        $this->modelRepository
            ->expects($this->once())
            ->method('findByTag')
            ->with($tag, true, null)
            ->willReturn([$model1, $model2]);

        $result = $this->service->getEligibleModels($tag, $userId);

        $this->assertCount(2, $result);
        $this->assertEquals(1, $result[0]['id']);
        $this->assertEquals('OpenAI', $result[0]['service']);
        $this->assertEquals('GPT-4', $result[0]['name']);
        $this->assertEquals('gpt-4', $result[0]['providerId']);
        $this->assertEquals(10.0, $result[0]['quality']);
        $this->assertEquals(4.5, $result[0]['rating']);
    }

    public function testGetEligibleModelsWithMinRating(): void
    {
        $tag = 'chat';
        $userId = 1;
        $minRating = 4.0;

        $config = $this->createMock(Config::class);
        $config->method('getValue')->willReturn((string)$minRating);

        $this->configRepository
            ->method('findOneBy')
            ->with([
                'ownerId' => $userId,
                'group' => 'SYSTEM_FLAGS',
                'setting' => 'MIN_MODEL_RATING'
            ])
            ->willReturn($config);

        $model1 = $this->createMockModel(1, 'OpenAI', 'GPT-4', 'chat', 'gpt-4', 10.0, 4.5);

        $this->modelRepository
            ->expects($this->once())
            ->method('findByTag')
            ->with($tag, true, $minRating)
            ->willReturn([$model1]);

        $result = $this->service->getEligibleModels($tag, $userId);

        $this->assertCount(1, $result);
    }

    public function testGetPredictedNextReturnsFirstWhenNoCurrentModel(): void
    {
        $models = [
            ['id' => 1, 'name' => 'Model 1'],
            ['id' => 2, 'name' => 'Model 2'],
        ];

        $result = $this->service->getPredictedNext($models, null);

        $this->assertEquals(1, $result['id']);
    }

    public function testGetPredictedNextReturnsNextModel(): void
    {
        $models = [
            ['id' => 1, 'name' => 'Model 1'],
            ['id' => 2, 'name' => 'Model 2'],
            ['id' => 3, 'name' => 'Model 3'],
        ];

        $result = $this->service->getPredictedNext($models, 1);

        $this->assertEquals(2, $result['id']);
    }

    public function testGetPredictedNextWrapsAroundAtEnd(): void
    {
        $models = [
            ['id' => 1, 'name' => 'Model 1'],
            ['id' => 2, 'name' => 'Model 2'],
            ['id' => 3, 'name' => 'Model 3'],
        ];

        $result = $this->service->getPredictedNext($models, 3);

        // Should wrap around to first model
        $this->assertEquals(1, $result['id']);
    }

    public function testGetPredictedNextReturnsFirstWhenCurrentNotFound(): void
    {
        $models = [
            ['id' => 1, 'name' => 'Model 1'],
            ['id' => 2, 'name' => 'Model 2'],
        ];

        $result = $this->service->getPredictedNext($models, 999);

        $this->assertEquals(1, $result['id']);
    }

    public function testGetPredictedNextReturnsNullForEmptyList(): void
    {
        $result = $this->service->getPredictedNext([], 1);

        $this->assertNull($result);
    }

    public function testResolveTagFromTopicForGeneral(): void
    {
        $result = $this->service->resolveTagFromTopic('general');
        $this->assertEquals('chat', $result);
    }

    public function testResolveTagFromTopicForMediaMaker(): void
    {
        $result = $this->service->resolveTagFromTopic('mediamaker');
        $this->assertEquals('text2pic', $result);
    }

    public function testResolveTagFromTopicForAnalyzeFile(): void
    {
        $result = $this->service->resolveTagFromTopic('analyzefile');
        $this->assertEquals('pic2text', $result);
    }

    public function testResolveTagFromTopicForToolsSort(): void
    {
        $result = $this->service->resolveTagFromTopic('tools:sort');
        $this->assertEquals('chat', $result);
    }

    public function testResolveTagFromTopicForToolsPic(): void
    {
        $result = $this->service->resolveTagFromTopic('tools:pic');
        $this->assertEquals('text2pic', $result);
    }

    public function testResolveTagFromTopicForToolsVid(): void
    {
        $result = $this->service->resolveTagFromTopic('tools:vid');
        $this->assertEquals('text2vid', $result);
    }

    public function testResolveTagFromTopicForToolsFileSort(): void
    {
        $result = $this->service->resolveTagFromTopic('tools:filesort');
        $this->assertEquals('vectorize', $result);
    }

    public function testResolveTagFromTopicDefaultsToChatForUnknown(): void
    {
        $result = $this->service->resolveTagFromTopic('unknown_topic');
        $this->assertEquals('chat', $result);
    }

    public function testGetEligibleModelsLogsInfo(): void
    {
        $tag = 'chat';

        $this->configRepository->method('findOneBy')->willReturn(null);
        $this->modelRepository->method('findByTag')->willReturn([]);

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with(
                'AgainService: Retrieved eligible models',
                $this->callback(function($context) use ($tag) {
                    return isset($context['tag']) &&
                           $context['tag'] === $tag && 
                           array_key_exists('count', $context) && 
                           array_key_exists('min_rating', $context);
                })
            );

        $this->service->getEligibleModels($tag);
    }

    public function testGetEligibleModelsHandlesExceptionInMinRating(): void
    {
        $tag = 'chat';

        $this->configRepository
            ->method('findOneBy')
            ->willThrowException(new \RuntimeException('Database error'));

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                'AgainService: Failed to get min rating',
                $this->arrayHasKey('error')
            );

        // Should not throw, should use null as min rating
        $this->modelRepository
            ->method('findByTag')
            ->with($tag, true, null)
            ->willReturn([]);

        $result = $this->service->getEligibleModels($tag);

        $this->assertIsArray($result);
    }

    public function testGetPredictedNextWithMiddleModel(): void
    {
        $models = [
            ['id' => 10, 'name' => 'Fast'],
            ['id' => 20, 'name' => 'Balanced'],
            ['id' => 30, 'name' => 'Quality'],
            ['id' => 40, 'name' => 'Premium'],
        ];

        $result = $this->service->getPredictedNext($models, 20);

        $this->assertEquals(30, $result['id']);
        $this->assertEquals('Quality', $result['name']);
    }

    // Helper method to create mock Model
    private function createMockModel(
        int $id,
        string $service,
        string $name,
        string $tag,
        string $providerId,
        float $quality,
        float $rating
    ): Model {
        $model = $this->createMock(Model::class);
        $model->method('getId')->willReturn($id);
        $model->method('getService')->willReturn($service);
        $model->method('getName')->willReturn($name);
        $model->method('getTag')->willReturn($tag);
        $model->method('getProviderId')->willReturn($providerId);
        $model->method('getQuality')->willReturn($quality);
        $model->method('getRating')->willReturn($rating);
        $model->method('getPriceIn')->willReturn(0.001);
        $model->method('getPriceOut')->willReturn(0.002);
        $model->method('getSelectable')->willReturn(1);

        return $model;
    }
}

