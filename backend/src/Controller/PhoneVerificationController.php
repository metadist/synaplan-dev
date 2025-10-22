<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\WhatsAppService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Phone Verification Controller
 * 
 * Handles phone number verification for WhatsApp integration
 */
#[Route('/api/v1/user/verify-phone', name: 'api_phone_verify_')]
class PhoneVerificationController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private WhatsAppService $whatsAppService,
        private LoggerInterface $logger
    ) {}

    /**
     * Request phone verification code
     * 
     * POST /api/v1/user/verify-phone/request
     */
    #[Route('/request', name: 'request', methods: ['POST'])]
    public function requestVerification(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->whatsAppService->isAvailable()) {
            return $this->json([
                'success' => false,
                'error' => 'WhatsApp service is not available'
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $data = json_decode($request->getContent(), true);
        $phoneNumber = $data['phone_number'] ?? '';

        if (empty($phoneNumber)) {
            return $this->json([
                'success' => false,
                'error' => 'Phone number is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Format phone number (remove spaces, dashes, etc.)
        $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);

        // Generate 6-digit verification code
        $verificationCode = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        // Store in user details JSON
        $userDetails = $user->getUserDetails();
        $userDetails['phone_verification'] = [
            'phone_number' => $phoneNumber,
            'code' => $verificationCode,
            'expires_at' => time() + 600, // 10 minutes
            'attempts' => 0,
            'verified' => false
        ];
        $user->setUserDetails($userDetails);
        
        $this->em->flush();

        // Send verification code via WhatsApp
        $message = sprintf(
            "🔐 *SynaPlan Verification*\n\nYour verification code is: *%s*\n\nThis code expires in 10 minutes.\n\nIf you didn't request this, please ignore this message.",
            $verificationCode
        );

        $result = $this->whatsAppService->sendMessage($phoneNumber, $message);

        if (!$result['success']) {
            $this->logger->error('Failed to send verification code', [
                'user_id' => $user->getId(),
                'phone' => $phoneNumber,
                'error' => $result['error']
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Failed to send verification code. Please check the phone number.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->logger->info('Verification code sent', [
            'user_id' => $user->getId(),
            'phone' => $phoneNumber
        ]);

        return $this->json([
            'success' => true,
            'message' => 'Verification code sent to your WhatsApp',
            'expires_in' => 600
        ]);
    }

    /**
     * Confirm phone verification code
     * 
     * POST /api/v1/user/verify-phone/confirm
     */
    #[Route('/confirm', name: 'confirm', methods: ['POST'])]
    public function confirmVerification(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $code = $data['code'] ?? '';

        if (empty($code)) {
            return $this->json([
                'success' => false,
                'error' => 'Verification code is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        $userDetails = $user->getUserDetails();
        $verification = $userDetails['phone_verification'] ?? null;

        if (!$verification) {
            return $this->json([
                'success' => false,
                'error' => 'No verification pending. Please request a new code.'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Check expiration
        if (time() > $verification['expires_at']) {
            return $this->json([
                'success' => false,
                'error' => 'Verification code expired. Please request a new one.',
                'expired' => true
            ], Response::HTTP_BAD_REQUEST);
        }

        // Check attempts
        if ($verification['attempts'] >= 3) {
            return $this->json([
                'success' => false,
                'error' => 'Too many failed attempts. Please request a new code.',
                'max_attempts' => true
            ], Response::HTTP_BAD_REQUEST);
        }

        // Verify code
        if ($code !== $verification['code']) {
            $verification['attempts']++;
            $userDetails['phone_verification'] = $verification;
            $user->setUserDetails($userDetails);
            $this->em->flush();

            return $this->json([
                'success' => false,
                'error' => 'Invalid verification code',
                'attempts_remaining' => 3 - $verification['attempts']
            ], Response::HTTP_BAD_REQUEST);
        }

        // Success - mark as verified
        $verification['verified'] = true;
        $userDetails['phone_number'] = $verification['phone_number'];
        $userDetails['phone_verified_at'] = time();
        unset($userDetails['phone_verification']); // Remove temp data
        
        $user->setUserDetails($userDetails);
        $this->em->flush();

        $this->logger->info('Phone verified successfully', [
            'user_id' => $user->getId(),
            'phone' => $verification['phone_number']
        ]);

        return $this->json([
            'success' => true,
            'message' => 'Phone number verified successfully',
            'phone_number' => $verification['phone_number']
        ]);
    }

    /**
     * Remove phone verification
     * 
     * DELETE /api/v1/user/verify-phone
     */
    #[Route('', name: 'remove', methods: ['DELETE'])]
    public function removeVerification(
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $userDetails = $user->getUserDetails();
        
        if (empty($userDetails['phone_number'])) {
            return $this->json([
                'success' => false,
                'error' => 'No phone number linked to this account'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Remove phone data
        unset($userDetails['phone_number']);
        unset($userDetails['phone_verified_at']);
        unset($userDetails['phone_verification']);
        
        $user->setUserDetails($userDetails);
        $this->em->flush();

        $this->logger->info('Phone verification removed', [
            'user_id' => $user->getId()
        ]);

        return $this->json([
            'success' => true,
            'message' => 'Phone number removed successfully'
        ]);
    }

    /**
     * Get current phone verification status
     * 
     * GET /api/v1/user/verify-phone/status
     */
    #[Route('/status', name: 'status', methods: ['GET'])]
    public function getStatus(
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $userDetails = $user->getUserDetails();
        $phoneNumber = $userDetails['phone_number'] ?? null;
        $verifiedAt = $userDetails['phone_verified_at'] ?? null;
        $pendingVerification = $userDetails['phone_verification'] ?? null;

        return $this->json([
            'success' => true,
            'phone_number' => $phoneNumber,
            'verified' => !empty($phoneNumber) && !empty($verifiedAt),
            'verified_at' => $verifiedAt,
            'pending_verification' => !empty($pendingVerification),
            'whatsapp_available' => $this->whatsAppService->isAvailable()
        ]);
    }
}

