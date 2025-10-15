<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\MessageRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Transparent File Serving
 * 
 * Handles /up/* URLs - serves files with auth check for private files
 */
#[Route('/up')]
class FileServeController extends AbstractController
{
    public function __construct(
        private MessageRepository $messageRepository,
        private string $uploadDir,
        private LoggerInterface $logger
    ) {}

    /**
     * Serve file by path
     * 
     * GET /up/{path}
     * Examples:
     * - /up/1/2025/10/image.jpg
     * - /up/1/2025/10/document.pdf
     */
    #[Route('/{path}', name: 'serve_file', requirements: ['path' => '.+'], methods: ['GET'])]
    public function serve(
        string $path,
        #[CurrentUser] ?User $user
    ): Response {
        // 1. Find message by file path WITH metadata (EAGER load)
        $message = $this->messageRepository->createQueryBuilder('m')
            ->leftJoin('m.metadata', 'meta')
            ->addSelect('meta')
            ->where('m.filePath = :path')
            ->setParameter('path', $path)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$message) {
            throw $this->createNotFoundException('File not found');
        }

        // 2. Check if file is public AND not expired
        $isPublicCheck = $message->isPublic();
        $isExpiredCheck = $message->isShareExpired();
        $isPublicAndValid = $isPublicCheck && !$isExpiredCheck;
        
        $this->logger->info('FileServeController: Access check', [
            'path' => $path,
            'message_id' => $message->getId(),
            'is_public' => $isPublicCheck,
            'is_expired' => $isExpiredCheck,
            'is_public_and_valid' => $isPublicAndValid,
            'has_user' => $user !== null,
            'user_id' => $user?->getId(),
            'owner_id' => $message->getUserId(),
            'metadata_count' => $message->getMetadata()->count()
        ]);

        // 3. Permission check: Public files OR authenticated owner
        if (!$isPublicAndValid) {
            // Not public - require authentication
            if (!$user) {
                $this->logger->warning('Unauthorized file access attempt', [
                    'path' => $path,
                    'is_public' => $message->isPublic(),
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
                return $this->json([
                    'error' => 'Authentication required'
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Check ownership (only owner can access private files)
            if ($message->getUserId() !== $user->getId()) {
                $this->logger->warning('Forbidden file access attempt', [
                    'path' => $path,
                    'user_id' => $user->getId(),
                    'owner_id' => $message->getUserId(),
                    'is_public' => $message->isPublic()
                ]);
                return $this->json([
                    'error' => 'Access denied'
                ], Response::HTTP_FORBIDDEN);
            }
        }

        // 4. Additional check for expired public shares (redundant but explicit)
        if ($message->isPublic() && $message->isShareExpired()) {
            $this->logger->info('Expired share link accessed', ['path' => $path]);
            return $this->json([
                'error' => 'Share link has expired'
            ], Response::HTTP_GONE);
        }

        // 4. Build absolute path
        $absolutePath = $this->uploadDir . '/' . $path;

        if (!file_exists($absolutePath)) {
            $this->logger->error('File not found on disk', [
                'path' => $path,
                'absolute_path' => $absolutePath
            ]);
            throw $this->createNotFoundException('File not found on disk');
        }

        // 5. Determine content disposition (inline vs download)
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $inlineTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'pdf', 'svg', 'txt', 'json', 'xml'];

        $disposition = in_array($extension, $inlineTypes)
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;

        // 6. Create response
        $filename = $message->getText()
            ? str_replace('File uploaded: ', '', $message->getText())
            : basename($path);

        $response = new BinaryFileResponse($absolutePath);
        $response->setContentDisposition($disposition, $filename);

        // 7. Cache headers
        if ($message->isPublic()) {
            // Public files: Aggressive caching
            $response->setPublic();
            $response->setMaxAge(3600); // 1 hour
            $response->headers->set('Cache-Control', 'public, max-age=3600');
        } else {
            // Private files: No caching
            $response->setPrivate();
            $response->headers->set('Cache-Control', 'private, no-cache, must-revalidate');
        }

        // 8. Security headers
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        $this->logger->info('File served', [
            'path' => $path,
            'user_id' => $user?->getId(),
            'is_public' => $message->isPublic(),
            'disposition' => $disposition
        ]);

        return $response;
    }
}

