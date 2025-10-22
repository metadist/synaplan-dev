<?php

namespace App\Tests\Unit;

use App\Service\Message\Handler\ChatHandler;
use App\AI\Service\AiFacade;
use App\Repository\PromptRepository;
use App\Service\ModelConfigService;
use App\Entity\Message;
use App\Entity\Prompt;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ChatHandlerTest extends TestCase
{
    private AiFacade $aiFacade;
    private PromptRepository $promptRepository;
    private ModelConfigService $modelConfigService;
    private LoggerInterface $logger;
    private ChatHandler $handler;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->promptRepository = $this->createMock(PromptRepository::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new ChatHandler(
            $this->aiFacade,
            $this->promptRepository,
            $this->modelConfigService,
            $this->logger
        );
    }

    public function testGetName(): void
    {
        $this->assertEquals('chat', $this->handler->getName());
    }

    public function testHandleUsesUserSelectedModel(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('Hello');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFileText')->willReturn('');

        $classification = [
            'topic' => 'CHAT',
            'language' => 'en',
            'model_id' => 42 // User-selected model
        ];

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getProviderForModel')->with(42)->willReturn('ollama');
        $this->modelConfigService->method('getModelName')->with(42)->willReturn('llama3');

        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->with(
                $this->anything(),
                1,
                $this->callback(function($options) {
                    return $options['provider'] === 'ollama' && $options['model'] === 'llama3';
                })
            )
            ->willReturn([
                'content' => 'Response text',
                'provider' => 'ollama',
                'model' => 'llama3'
            ]);

        $result = $this->handler->handle($message, [], $classification);

        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('metadata', $result);
        $this->assertEquals('Response text', $result['content']);
    }

    public function testHandleFallsBackToDefaultModel(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('Hello');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFileText')->willReturn('');

        $classification = [
            'topic' => 'CHAT',
            'language' => 'en'
        ];

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getDefaultModel')->with('CHAT', 1)->willReturn(10);
        $this->modelConfigService->method('getProviderForModel')->with(10)->willReturn('openai');
        $this->modelConfigService->method('getModelName')->with(10)->willReturn('gpt-4');

        $this->aiFacade
            ->method('chat')
            ->willReturn([
                'content' => 'Response',
                'provider' => 'openai',
                'model' => 'gpt-4'
            ]);

        $result = $this->handler->handle($message, [], $classification);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('content', $result);
    }

    public function testHandleExtractsJsonResponse(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('Test');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFileText')->willReturn('');

        $classification = ['topic' => 'CHAT', 'language' => 'en'];

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $jsonResponse = json_encode([
            'BTEXT' => 'Extracted text content',
            'BFILE' => 1,
            'BFILETEXT' => '/path/to/file.jpg'
        ]);

        $this->aiFacade
            ->method('chat')
            ->willReturn([
                'content' => $jsonResponse,
                'provider' => 'test',
                'model' => 'test'
            ]);

        $result = $this->handler->handle($message, [], $classification);

        $this->assertEquals('Extracted text content', $result['content']);
        $this->assertArrayHasKey('file', $result['metadata']);
    }

    public function testHandleIncludesThreadMessages(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('Current message');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFileText')->willReturn('');

        $threadMsg = $this->createMock(Message::class);
        $threadMsg->method('getDirection')->willReturn('IN');
        $threadMsg->method('getText')->willReturn('Previous message');
        $threadMsg->method('getDateTime')->willReturn('20250116115900');

        $thread = [$threadMsg];
        $classification = ['topic' => 'CHAT', 'language' => 'en'];

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function($messages) {
                    // Should have system, thread, and current message
                    return count($messages) >= 3;
                }),
                $this->anything(),
                $this->anything()
            )
            ->willReturn([
                'content' => 'Response',
                'provider' => 'test',
                'model' => 'test'
            ]);

        $this->handler->handle($message, $thread, $classification);
    }

    public function testHandleCallsProgressCallback(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('Test');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFileText')->willReturn('');

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->aiFacade->method('chat')->willReturn([
            'content' => 'Response',
            'provider' => 'test',
            'model' => 'test'
        ]);

        $callbackCalled = 0;
        $callback = function($status) use (&$callbackCalled) {
            $callbackCalled++;
            $this->assertArrayHasKey('status', $status);
            $this->assertArrayHasKey('message', $status);
        };

        $this->handler->handle($message, [], ['topic' => 'CHAT', 'language' => 'en'], $callback);

        $this->assertGreaterThan(0, $callbackCalled);
    }

    public function testHandleUsesUserPrompt(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(5);
        $message->method('getText')->willReturn('Test');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('de');
        $message->method('getFileText')->willReturn('');

        $userPrompt = $this->createMock(Prompt::class);
        $userPrompt->method('getPrompt')->willReturn('Custom user prompt');

        $this->promptRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['ownerId' => 5, 'language' => 'de'])
            ->willReturn($userPrompt);

        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->aiFacade->method('chat')->willReturn([
            'content' => 'Response',
            'provider' => 'test',
            'model' => 'test'
        ]);

        $this->handler->handle($message, [], ['topic' => 'CHAT', 'language' => 'de']);
    }

    public function testHandleStreamCallsStreamCallback(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('Stream test');
        $message->method('getFileText')->willReturn('');

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $chunks = [];
        $streamCallback = function($chunk) use (&$chunks) {
            $chunks[] = $chunk;
        };

        $this->aiFacade
            ->expects($this->once())
            ->method('chatStream')
            ->with(
                $this->anything(),
                $streamCallback,
                1,
                $this->anything()
            )
            ->willReturn(['provider' => 'test', 'model' => 'test']);

        $this->handler->handleStream(
            $message,
            [],
            ['topic' => 'CHAT', 'language' => 'en'],
            $streamCallback
        );
    }
}

