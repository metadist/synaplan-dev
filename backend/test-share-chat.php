#!/usr/bin/env php
<?php

/**
 * Test Script: Share einen Chat und zeige den öffentlichen Link
 * 
 * Usage: docker compose exec backend php test-share-chat.php
 */

require __DIR__ . '/vendor/autoload.php';

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

// Load environment
(new Dotenv())->bootEnv(__DIR__ . '/.env');

// Boot Symfony Kernel
$kernel = new Kernel($_ENV['APP_ENV'] ?? 'dev', (bool) ($_ENV['APP_DEBUG'] ?? true));
$kernel->boot();

$container = $kernel->getContainer();
$em = $container->get('doctrine')->getManager();
$chatRepo = $em->getRepository(\App\Entity\Chat::class);
$userRepo = $em->getRepository(\App\Entity\User::class);
$messageRepo = $em->getRepository(\App\Entity\Message::class);

echo "\n";
echo "╔═══════════════════════════════════════════════════════════════════╗\n";
echo "║  🧪 CHAT-SHARING TEST SCRIPT  🧪                                 ║\n";
echo "╚═══════════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Find first user
$user = $userRepo->findOneBy([]);

if (!$user) {
    echo "❌ ERROR: No users found in database!\n";
    echo "   Create a user first via the web interface.\n\n";
    exit(1);
}

echo "✅ Found user: {$user->getMail()} (ID: {$user->getId()})\n";

// Find or create a chat
$chat = $chatRepo->findOneBy(['userId' => $user->getId()]);

if (!$chat) {
    echo "📝 Creating new chat...\n";
    $chat = new \App\Entity\Chat();
    $chat->setUserId($user->getId());
    $chat->setTitle('Shared Demo Chat');
    $chat->setCreatedAt(new \DateTime());
    $chat->setUpdatedAt(new \DateTime());
    $em->persist($chat);
    $em->flush();
    
    // Add demo messages
    echo "📝 Adding demo messages...\n";
    
    $msg1 = new \App\Entity\Message();
    $msg1->setUserId($user->getId());
    $msg1->setChat($chat);
    $msg1->setText('Hello! This is a demo shared chat.');
    $msg1->setDirection('IN');
    $msg1->setUnixTimestamp(time());
    $msg1->setTrackingId(time());
    $msg1->setProviderIndex('SYSTEM');
    $msg1->setMessageType('TEXT');
    $msg1->setTopic('demo');
    $msg1->setLanguage('en');
    $msg1->setStatus('COMPLETE');
    $em->persist($msg1);
    
    $msg2 = new \App\Entity\Message();
    $msg2->setUserId($user->getId());
    $msg2->setChat($chat);
    $msg2->setText('This is a response from the AI. You can share this conversation with anyone!');
    $msg2->setDirection('OUT');
    $msg2->setUnixTimestamp(time() + 1);
    $msg2->setTrackingId(time());
    $msg2->setProviderIndex('ollama/llama3.2');
    $msg2->setMessageType('TEXT');
    $msg2->setTopic('demo');
    $msg2->setLanguage('en');
    $msg2->setStatus('COMPLETE');
    $em->persist($msg2);
    
    $msg3 = new \App\Entity\Message();
    $msg3->setUserId($user->getId());
    $msg3->setChat($chat);
    $msg3->setText('What can you tell me about AI and machine learning?');
    $msg3->setDirection('IN');
    $msg3->setUnixTimestamp(time() + 2);
    $msg3->setTrackingId(time() + 1);
    $msg3->setProviderIndex('SYSTEM');
    $msg3->setMessageType('TEXT');
    $msg3->setTopic('demo');
    $msg3->setLanguage('en');
    $msg3->setStatus('COMPLETE');
    $em->persist($msg3);
    
    $msg4 = new \App\Entity\Message();
    $msg4->setUserId($user->getId());
    $msg4->setChat($chat);
    $msg4->setText("AI (Artificial Intelligence) and Machine Learning are fascinating fields! AI refers to computer systems that can perform tasks typically requiring human intelligence, while Machine Learning is a subset of AI where systems learn from data without being explicitly programmed. These technologies are revolutionizing everything from healthcare to transportation!");
    $msg4->setDirection('OUT');
    $msg4->setUnixTimestamp(time() + 3);
    $msg4->setTrackingId(time() + 1);
    $msg4->setProviderIndex('ollama/llama3.2');
    $msg4->setMessageType('TEXT');
    $msg4->setTopic('demo');
    $msg4->setLanguage('en');
    $msg4->setStatus('COMPLETE');
    $em->persist($msg4);
    
    $em->flush();
    echo "✅ Created chat with 4 demo messages\n";
} else {
    echo "✅ Found existing chat: {$chat->getTitle()} (ID: {$chat->getId()})\n";
}

// Check if already shared
if ($chat->isPublic() && $chat->getShareToken()) {
    echo "ℹ️  Chat is already shared!\n";
} else {
    echo "📤 Making chat public...\n";
    
    // Generate share token
    if (!$chat->getShareToken()) {
        $chat->generateShareToken();
    }
    
    // Make public
    $chat->setIsPublic(true);
    $em->flush();
    
    echo "✅ Chat is now public!\n";
}

// Display info
$messageCount = count($messageRepo->findBy(['chatId' => $chat->getId()]));

echo "\n";
echo "╔═══════════════════════════════════════════════════════════════════╗\n";
echo "║  ✅ CHAT SHARING SUCCESSFUL!  ✅                                 ║\n";
echo "╚═══════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "📊 CHAT DETAILS:\n";
echo "   • ID: {$chat->getId()}\n";
echo "   • Title: {$chat->getTitle()}\n";
echo "   • Owner: {$user->getMail()}\n";
echo "   • Messages: {$messageCount}\n";
echo "   • Share Token: {$chat->getShareToken()}\n";
echo "   • Is Public: " . ($chat->isPublic() ? 'Yes ✅' : 'No ❌') . "\n";
echo "\n";
echo "🌐 PUBLIC URL:\n";
echo "   → http://localhost:5173/shared/{$chat->getShareToken()}\n";
echo "\n";
echo "📋 API ENDPOINT:\n";
echo "   → http://localhost:8000/api/v1/chats/shared/{$chat->getShareToken()}\n";
echo "\n";
echo "🧪 TEST IT:\n";
echo "   1. Open the public URL in your browser\n";
echo "   2. You should see the chat messages\n";
echo "   3. No login required!\n";
echo "\n";
echo "🔄 REVOKE SHARING:\n";
echo "   curl -X POST http://localhost:8000/api/v1/chats/{$chat->getId()}/share \\\n";
echo "        -H 'Authorization: Bearer YOUR_TOKEN' \\\n";
echo "        -H 'Content-Type: application/json' \\\n";
echo "        -d '{\"enable\": false}'\n";
echo "\n";

