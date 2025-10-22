<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AuthControllerTest extends WebTestCase
{
    private $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    // =========== Register Tests ===========

    public function testRegisterSuccess(): void
    {
        $uniqueEmail = 'testuser_' . time() . '@example.com';
        
        $this->client->request('POST', '/api/v1/auth/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => $uniqueEmail,
            'password' => 'SecurePass123!'
        ]));

        $response = $this->client->getResponse();
        $this->assertEquals(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('userId', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertStringContainsString('verification', strtolower($data['message']));
    }

    public function testRegisterWithDuplicateEmail(): void
    {
        $email = 'duplicate_' . time() . '@example.com';
        
        // Register first user
        $this->client->request('POST', '/api/v1/auth/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => $email,
            'password' => 'SecurePass123!'
        ]));

        // Try to register again with same email
        $this->client->request('POST', '/api/v1/auth/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => $email,
            'password' => 'AnotherPass456!'
        ]));

        $response = $this->client->getResponse();
        $this->assertEquals(409, $response->getStatusCode()); // Conflict

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('already registered', $data['error']);
    }

    public function testRegisterWithInvalidEmail(): void
    {
        $this->client->request('POST', '/api/v1/auth/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'not-an-email',
            'password' => 'SecurePass123!'
        ]));

        $response = $this->client->getResponse();
        
        // Should return validation error (400 or 422)
        $this->assertContains($response->getStatusCode(), [400, 422]);
    }

    public function testRegisterWithMissingPassword(): void
    {
        $this->client->request('POST', '/api/v1/auth/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'test@example.com'
        ]));

        $response = $this->client->getResponse();
        
        $this->assertContains($response->getStatusCode(), [400, 422]);
    }

    // =========== Login Tests ===========

    public function testLoginWithoutVerification(): void
    {
        // Register new user
        $email = 'unverified_' . time() . '@example.com';
        
        $this->client->request('POST', '/api/v1/auth/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => $email,
            'password' => 'SecurePass123!'
        ]));

        // Try to login without verifying
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => $email,
            'password' => 'SecurePass123!'
        ]));

        $response = $this->client->getResponse();
        $this->assertEquals(403, $response->getStatusCode()); // Forbidden

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('not verified', strtolower($data['error']));
    }

    public function testLoginWithInvalidCredentials(): void
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'nonexistent@example.com',
            'password' => 'wrongpassword'
        ]));

        $response = $this->client->getResponse();
        $this->assertEquals(401, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Invalid credentials', $data['error']);
    }

    public function testLoginWithMissingEmail(): void
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'password' => 'test'
        ]));

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testLoginWithMissingPassword(): void
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'test@example.com'
        ]));

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
    }

    // =========== Email Verification Tests ===========

    public function testVerifyEmailWithInvalidToken(): void
    {
        $this->client->request('POST', '/api/v1/auth/verify-email', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'token' => 'invalid_token_12345'
        ]));

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Invalid or expired', $data['error']);
    }

    public function testVerifyEmailWithMissingToken(): void
    {
        $this->client->request('POST', '/api/v1/auth/verify-email', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Token required', $data['error']);
    }

    // =========== Forgot Password Tests ===========

    public function testForgotPasswordReturnsGenericMessage(): void
    {
        // Should return success even for non-existent email (security)
        $this->client->request('POST', '/api/v1/auth/forgot-password', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'nonexistent_' . time() . '@example.com'
        ]));

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('If email exists', $data['message']);
    }

    public function testForgotPasswordWithMissingEmail(): void
    {
        $this->client->request('POST', '/api/v1/auth/forgot-password', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Email required', $data['error']);
    }

    // =========== Reset Password Tests ===========

    public function testResetPasswordWithInvalidToken(): void
    {
        $this->client->request('POST', '/api/v1/auth/reset-password', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'token' => 'invalid_reset_token',
            'password' => 'NewSecurePass123!'
        ]));

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Invalid or expired', $data['error']);
    }

    public function testResetPasswordWithShortPassword(): void
    {
        $this->client->request('POST', '/api/v1/auth/reset-password', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'token' => 'some_token',
            'password' => 'short'
        ]));

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('at least 8 characters', $data['error']);
    }

    public function testResetPasswordWithMissingFields(): void
    {
        $this->client->request('POST', '/api/v1/auth/reset-password', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'token' => 'some_token'
        ]));

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Token and password required', $data['error']);
    }

    // =========== Resend Verification Tests ===========

    public function testResendVerificationWithMissingEmail(): void
    {
        $this->client->request('POST', '/api/v1/auth/resend-verification', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Email required', $data['error']);
    }

    public function testResendVerificationReturnsGenericMessage(): void
    {
        // Should return generic message even for non-existent email
        $this->client->request('POST', '/api/v1/auth/resend-verification', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'nonexistent_' . time() . '@example.com'
        ]));

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('If your email', $data['message']);
    }

    // =========== Me Endpoint Tests ===========

    public function testMeWithoutAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/auth/me');

        $response = $this->client->getResponse();
        $this->assertEquals(401, $response->getStatusCode());
    }

    // =========== Logout Tests ===========

    public function testLogout(): void
    {
        $this->client->request('POST', '/api/v1/auth/logout');

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    // =========== Security Tests ===========

    public function testTimingAttackPrevention(): void
    {
        // Test login with non-existent user
        $start = microtime(true);
        
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'nonexistent@example.com',
            'password' => 'somepassword'
        ]));
        
        $duration = microtime(true) - $start;

        // Should have timing delay (> 0.1 seconds)
        $this->assertGreaterThan(0.1, $duration, 'Login should have timing attack prevention');
    }

    public function testPasswordIsHashed(): void
    {
        $email = 'hashtest_' . time() . '@example.com';
        $password = 'PlainTextPassword123!';
        
        $this->client->request('POST', '/api/v1/auth/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => $email,
            'password' => $password
        ]));

        $this->assertEquals(201, $this->client->getResponse()->getStatusCode());

        // Check that password is hashed in database
        $userRepository = static::getContainer()->get(UserRepository::class);
        $user = $userRepository->findOneBy(['mail' => $email]);

        $this->assertNotNull($user);
        $this->assertNotEquals($password, $user->getPassword(), 'Password should be hashed');
        $this->assertStringStartsWith('$', $user->getPassword(), 'Should be bcrypt/argon2 hash');
    }

    public function testNewUserDefaultValues(): void
    {
        $email = 'defaults_' . time() . '@example.com';
        
        $this->client->request('POST', '/api/v1/auth/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => $email,
            'password' => 'SecurePass123!'
        ]));

        $userRepository = static::getContainer()->get(UserRepository::class);
        $user = $userRepository->findOneBy(['mail' => $email]);

        $this->assertNotNull($user);
        $this->assertEquals('WEB', $user->getType());
        $this->assertEquals('NEW', $user->getUserLevel());
        $this->assertEquals('local', $user->getProviderId());
        $this->assertFalse($user->isEmailVerified());
        $this->assertNotEmpty($user->getCreated());
    }
}

