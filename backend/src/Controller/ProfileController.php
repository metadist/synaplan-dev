<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/profile', name: 'api_profile_')]
class ProfileController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private LoggerInterface $logger
    ) {}

    #[Route('', name: 'get', methods: ['GET'])]
    public function getProfile(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $details = $user->getUserDetails();

        return $this->json([
            'success' => true,
            'profile' => [
                'email' => $user->getMail(),
                'firstName' => $details['firstName'] ?? '',
                'lastName' => $details['lastName'] ?? '',
                'phone' => $details['phone'] ?? '',
                'companyName' => $details['companyName'] ?? '',
                'vatId' => $details['vatId'] ?? '',
                'street' => $details['street'] ?? '',
                'zipCode' => $details['zipCode'] ?? '',
                'city' => $details['city'] ?? '',
                'country' => $details['country'] ?? '',
                'language' => $details['language'] ?? 'en',
                'timezone' => $details['timezone'] ?? '',
                'invoiceEmail' => $details['invoiceEmail'] ?? '',
            ]
        ]);
    }

    #[Route('', name: 'update', methods: ['PUT', 'PATCH'])]
    public function updateProfile(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        // Get current details
        $details = $user->getUserDetails();

        // Update allowed fields
        $allowedFields = [
            'firstName', 'lastName', 'phone', 'companyName', 'vatId',
            'street', 'zipCode', 'city', 'country', 'language', 'timezone', 'invoiceEmail'
        ];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $details[$field] = $data[$field];
            }
        }

        $user->setUserDetails($details);
        $this->em->flush();

        $this->logger->info('Profile updated', ['user_id' => $user->getId()]);

        return $this->json([
            'success' => true,
            'message' => 'Profile updated successfully'
        ]);
    }

    #[Route('/password', name: 'change_password', methods: ['PUT'])]
    public function changePassword(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        $currentPassword = $data['currentPassword'] ?? '';
        $newPassword = $data['newPassword'] ?? '';

        if (empty($currentPassword) || empty($newPassword)) {
            return $this->json([
                'error' => 'Current password and new password required'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Verify current password
        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            return $this->json([
                'error' => 'Current password is incorrect'
            ], Response::HTTP_FORBIDDEN);
        }

        // Validate new password
        if (strlen($newPassword) < 8) {
            return $this->json([
                'error' => 'New password must be at least 8 characters'
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', $newPassword)) {
            return $this->json([
                'error' => 'Password must contain at least one uppercase letter, one lowercase letter, and one number'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Update password
        $user->setPw($this->passwordHasher->hashPassword($user, $newPassword));
        $this->em->flush();

        $this->logger->info('Password changed', ['user_id' => $user->getId()]);

        return $this->json([
            'success' => true,
            'message' => 'Password changed successfully'
        ]);
    }
}

