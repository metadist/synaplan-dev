<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for MessageAgainController
 */
class MessageAgainControllerTest extends WebTestCase
{
    private $client;
    private $em;
    private $user;
    private $token;
    private $chat;
    private $message;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        
        // Create test user
        $this->user = new User();
        $this->user->setMail('againtest@example.com');
        $this->user->setPw(password_hash('testpass', PASSWORD_BCRYPT));
        $this->user->setUserLevel('PRO');
        $this->user->setProviderId('test-provider');
        $this->user->setCreated(date('YmdHis'));
        
        $this->em->persist($this->user);
        $this->em->flush();

        // Create test chat
        $this->chat = new Chat();
        $this->chat->setUserId($this->user->getId());
        $this->chat->setTitle('Test Chat');
        $this->chat->setCreatedAt(new \DateTime());
        $this->chat->setUpdatedAt(new \DateTime());
        
        $this->em->persist($this->chat);
        $this->em->flush();

        // Create test message
        $this->message = new Message();
        $this->message->setUserId($this->user->getId());
        $this->message->setChatId($this->chat->getId());
        $this->message->setTrackingId(time());
        $this->message->setText('Test message');
        $this->message->setDirection('IN');
        $this->message->setMessageType('WEB');
        $this->message->setUnixTimestamp(time());
        $this->message->setDateTime(date('YmdHis'));
        
        $this->em->persist($this->message);
        $this->em->flush();

        // Generate JWT token
        $jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        $this->token = $jwtManager->create($this->user);
    }

    protected function tearDown(): void
    {
        if ($this->em && $this->user) {
            // Remove test message
            if ($this->message) {
                $this->em->remove($this->message);
            }
            
            // Remove test chat
            if ($this->chat) {
                $this->em->remove($this->chat);
            }
            
            // Remove test user
            $this->em->remove($this->user);
            $this->em->flush();
        }
        
        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testGetAgainOptionsWithoutAuth(): void
    {
        $this->client->request(
            'GET',
            sprintf('/api/v1/messages/%d/again-options', $this->message->getId())
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGetAgainOptionsWithAuth(): void
    {
        $this->client->request(
            'GET',
            sprintf('/api/v1/messages/%d/again-options', $this->message->getId()),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]
        );

        $this->assertResponseIsSuccessful();
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertArrayHasKey('success', $responseData);
        $this->assertTrue($responseData['success']);
        
        $this->assertArrayHasKey('eligible_models', $responseData);
        $this->assertIsArray($responseData['eligible_models']);
    }

    public function testGetAgainOptionsForNonExistentMessage(): void
    {
        $nonExistentId = 999999999;
        
        $this->client->request(
            'GET',
            sprintf('/api/v1/messages/%d/again-options', $nonExistentId),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testGetAgainOptionsForOtherUsersMessage(): void
    {
        // Create another user
        $otherUser = new User();
        $otherUser->setMail('other@example.com');
        $otherUser->setPw(password_hash('testpass', PASSWORD_BCRYPT));
        $otherUser->setUserLevel('PRO');
        $otherUser->setProviderId('test-provider');
        $otherUser->setCreated(date('YmdHis'));
        
        $this->em->persist($otherUser);
        $this->em->flush();

        // Create message for other user
        $otherMessage = new Message();
        $otherMessage->setUserId($otherUser->getId());
        $otherMessage->setChatId($this->chat->getId());
        $otherMessage->setTrackingId(time() + 1);
        $otherMessage->setText('Other user message');
        $otherMessage->setDirection('IN');
        $otherMessage->setMessageType('WEB');
        $otherMessage->setUnixTimestamp(time());
        $otherMessage->setDateTime(date('YmdHis'));
        
        $this->em->persist($otherMessage);
        $this->em->flush();

        // Try to access other user's message
        $this->client->request(
            'GET',
            sprintf('/api/v1/messages/%d/again-options', $otherMessage->getId()),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // Cleanup
        $this->em->remove($otherMessage);
        $this->em->remove($otherUser);
        $this->em->flush();
    }

    public function testGetAgainOptionsIncludesPredictedModel(): void
    {
        $this->client->request(
            'GET',
            sprintf('/api/v1/messages/%d/again-options', $this->message->getId()),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]
        );

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertArrayHasKey('predicted_next', $responseData);
    }
}

