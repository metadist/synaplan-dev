<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\MessageFile;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Repository\MessageFileRepository;
use App\Service\File\FileProcessor;
use App\Service\File\FileStorageService;
use App\Service\File\VectorizationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/files', name: 'api_files_')]
class FileController extends AbstractController
{
    public function __construct(
        private FileStorageService $storageService,
        private FileProcessor $fileProcessor,
        private VectorizationService $vectorizationService,
        private MessageRepository $messageRepository,
        private MessageFileRepository $messageFileRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {}

    /**
     * Upload file(s) with flexible processing pipeline
     * 
     * POST /api/v1/files/upload
     * 
     * Form-Data:
     * - files[]: File(s) to upload (multipart/form-data)
     * - group_key: Optional grouping keyword for vectorization (default: 'DEFAULT')
     * - process_level: Processing level ('extract' | 'vectorize' | 'full') (default: 'vectorize')
     *   - extract: File storage + text extraction only
     *   - vectorize: extract + vector embeddings (default)
     *   - full: vectorize + AI processing (future: summarization, analysis)
     * 
     * Response:
     * {
     *   "success": true,
     *   "files": [
     *     {
     *       "id": 123,
     *       "filename": "document.pdf",
     *       "size": 1024000,
     *       "extracted_text_length": 5000,
     *       "chunks_created": 12,
     *       "processing_time_ms": 1500
     *     }
     *   ],
     *   "errors": []
     * }
     */
    #[Route('/upload', name: 'upload', methods: ['POST'])]
    public function uploadFiles(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $startTime = microtime(true);
        
        // Get parameters
        $groupKey = $request->request->get('group_key', 'DEFAULT');
        $processLevel = $request->request->get('process_level', 'vectorize');
        
        // Validate process level
        if (!in_array($processLevel, ['extract', 'vectorize', 'full'], true)) {
            return $this->json([
                'error' => 'Invalid process_level. Must be: extract, vectorize, or full'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Get uploaded files
        $uploadedFiles = $request->files->get('files', []);
        if (empty($uploadedFiles)) {
            return $this->json([
                'error' => 'No files uploaded. Use form-data with files[] field'
            ], Response::HTTP_BAD_REQUEST);
        }

        $results = [];
        $errors = [];

        foreach ($uploadedFiles as $uploadedFile) {
            $fileStartTime = microtime(true);
            
            try {
                $result = $this->processUploadedFile(
                    $uploadedFile,
                    $user,
                    $groupKey,
                    $processLevel
                );
                
                if ($result['success']) {
                    $result['processing_time_ms'] = (int)((microtime(true) - $fileStartTime) * 1000);
                    $results[] = $result;
                } else {
                    $errors[] = [
                        'filename' => $uploadedFile->getClientOriginalName(),
                        'error' => $result['error']
                    ];
                }
                
            } catch (\Throwable $e) {
                $this->logger->error('FileController: File upload failed', [
                    'filename' => $uploadedFile->getClientOriginalName(),
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage()
                ]);
                
                $errors[] = [
                    'filename' => $uploadedFile->getClientOriginalName(),
                    'error' => 'Upload failed: ' . $e->getMessage()
                ];
            }
        }

        $totalTime = (int)((microtime(true) - $startTime) * 1000);

        return $this->json([
            'success' => count($errors) === 0,
            'files' => $results,
            'errors' => $errors,
            'total_time_ms' => $totalTime,
            'process_level' => $processLevel
        ], count($errors) === 0 ? Response::HTTP_OK : Response::HTTP_PARTIAL_CONTENT);
    }

    /**
     * Process single uploaded file
     */
    private function processUploadedFile(
        $uploadedFile,
        User $user,
        string $groupKey,
        string $processLevel
    ): array {
        // Step 1: Store file
        $storageResult = $this->storageService->storeUploadedFile($uploadedFile, $user->getId());
        
        if (!$storageResult['success']) {
            return [
                'success' => false,
                'error' => $storageResult['error']
            ];
        }

        $relativePath = $storageResult['path'];
        $fileExtension = strtolower($uploadedFile->getClientOriginalExtension());
        
        // Create MessageFile entity (standalone, not attached to a message yet)
        $messageFile = new MessageFile();
        $messageFile->setUserId($user->getId());
        $messageFile->setFilePath($relativePath);
        $messageFile->setFileType($fileExtension);
        $messageFile->setFileName($uploadedFile->getClientOriginalName());
        $messageFile->setFileSize($storageResult['size']);
        $messageFile->setFileMime($storageResult['mime']);
        $messageFile->setStatus('uploaded');
        
        $this->em->persist($messageFile);
        $this->em->flush();

        $result = [
            'success' => true,
            'id' => $messageFile->getId(),
            'filename' => $uploadedFile->getClientOriginalName(),
            'size' => $storageResult['size'],
            'mime' => $storageResult['mime'],
            'path' => $relativePath,
            'group_key' => $groupKey
        ];

        // Step 2: Extract text (ALWAYS done - Preprocessor)
        try {
            [$extractedText, $extractMeta] = $this->fileProcessor->extractText(
                $relativePath,
                $fileExtension,
                $user->getId()
            );

            $messageFile->setFileText($extractedText);
            $messageFile->setStatus('extracted');
            $this->em->flush();

            $result['extracted_text_length'] = strlen($extractedText);
            $result['extraction_strategy'] = $extractMeta['strategy'] ?? 'unknown';

            // Stop here if only extraction requested
            if ($processLevel === 'extract') {
                return $result;
            }

        } catch (\Throwable $e) {
            $this->logger->error('FileController: Text extraction failed', [
                'file_id' => $messageFile->getId(),
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => 'Text extraction failed: ' . $e->getMessage()
            ];
        }

        // Step 3: Vectorize (if requested)
        if (in_array($processLevel, ['vectorize', 'full'], true)) {
            try {
                $vectorResult = $this->vectorizationService->vectorizeAndStore(
                    $extractedText,
                    $user->getId(),
                    $messageFile->getId(),
                    $groupKey,
                    $this->getFileTypeCode($fileExtension)
                );

                if ($vectorResult['success']) {
                    $messageFile->setStatus('vectorized');
                    $this->em->flush();

                    $result['chunks_created'] = $vectorResult['chunks_created'];
                    $result['vectorized'] = true;
                } else {
                    $this->logger->warning('FileController: Vectorization failed', [
                        'file_id' => $messageFile->getId(),
                        'error' => $vectorResult['error']
                    ]);
                    
                    $result['vectorized'] = false;
                    $result['vectorization_error'] = $vectorResult['error'];
                }

            } catch (\Throwable $e) {
                $this->logger->error('FileController: Vectorization exception', [
                    'file_id' => $messageFile->getId(),
                    'error' => $e->getMessage()
                ]);
                
                $result['vectorized'] = false;
                $result['vectorization_error'] = $e->getMessage();
            }
        }

        // Step 4: Full processing (future: AI analysis, summarization)
        if ($processLevel === 'full') {
            // TODO: Implement AI processing
            // - Generate summary
            // - Extract entities
            // - Create structured metadata
            $result['ai_processed'] = false;
            $result['ai_processing_note'] = 'AI processing not yet implemented';
        }

        return $result;
    }

    /**
     * Get file type code for BRAG table
     */
    private function getFileTypeCode(string $extension): int
    {
        return match(strtolower($extension)) {
            'txt', 'md', 'csv' => 0, // Plain text
            'jpg', 'jpeg', 'png', 'gif', 'webp' => 1, // Image
            'mp3', 'mp4', 'wav', 'ogg', 'm4a', 'webm' => 2, // Audio/Video
            'pdf' => 3, // PDF
            'docx', 'doc', 'xlsx', 'xls', 'pptx', 'ppt' => 4, // Office
            default => 5 // Other
        };
    }

    /**
     * Download a file
     * 
     * GET /api/v1/files/{id}/download
     */
    #[Route('/{id}/download', name: 'download', methods: ['GET'])]
    public function downloadFile(
        int $id,
        #[CurrentUser] ?User $user
    ): Response {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Get the message entity WITH metadata (EAGER load)
        $message = $this->messageRepository->createQueryBuilder('m')
            ->leftJoin('m.metadata', 'meta')
            ->addSelect('meta')
            ->where('m.id = :id')
            ->andWhere('m.file = 1')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$message) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        // Security check: Owner can always download, others only if public
        $isOwner = $message->getUserId() === $user->getId();
        $isPublicAndValid = $message->isPublic() && !$message->isShareExpired();

        if (!$isOwner && !$isPublicAndValid) {
            $this->logger->warning('FileController: Unauthorized download attempt', [
                'file_id' => $id,
                'user_id' => $user->getId(),
                'owner_id' => $message->getUserId(),
                'is_public' => $message->isPublic(),
                'is_expired' => $message->isShareExpired()
            ]);
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $filePath = $message->getFilePath();
        if (!$filePath) {
            return $this->json(['error' => 'File path not found'], Response::HTTP_NOT_FOUND);
        }

        $absolutePath = $this->uploadDir . '/' . $filePath;

        if (!file_exists($absolutePath)) {
            $this->logger->error('FileController: File not found on disk', [
                'message_id' => $id,
                'path' => $absolutePath
            ]);
            return $this->json(['error' => 'File not found on disk'], Response::HTTP_NOT_FOUND);
        }

        // Get original filename from Message text or path
        $filename = $message->getText() 
            ? str_replace('File uploaded: ', '', $message->getText())
            : basename($filePath);

        // Return file as download
        $response = new BinaryFileResponse($absolutePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        );
        
        return $response;
    }

    /**
     * Get file content/text
     * 
     * GET /api/v1/files/{id}/content
     */
    #[Route('/{id}/content', name: 'content', methods: ['GET'])]
    public function getFileContent(
        int $id,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Get the message entity WITH metadata (EAGER load)
        $message = $this->messageRepository->createQueryBuilder('m')
            ->leftJoin('m.metadata', 'meta')
            ->addSelect('meta')
            ->where('m.id = :id')
            ->andWhere('m.file = 1')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$message) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        // Security check: Owner can always view, others only if public
        $isOwner = $message->getUserId() === $user->getId();
        $isPublicAndValid = $message->isPublic() && !$message->isShareExpired();

        if (!$isOwner && !$isPublicAndValid) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $filename = $message->getText() 
            ? str_replace('File uploaded: ', '', $message->getText())
            : basename($message->getFilePath() ?? '');

        return $this->json([
            'id' => $message->getId(),
            'filename' => $filename,
            'file_path' => $message->getFilePath(),
            'file_type' => $message->getFileType(),
            'extracted_text' => $message->getFileText() ?? '',
            'status' => $message->getStatus(),
            'uploaded_at' => $message->getUnixTimestamp(),
            'uploaded_date' => date('Y-m-d H:i:s', $message->getUnixTimestamp())
        ]);
    }

    /**
     * List user's uploaded files
     * 
     * GET /api/v1/files
     * Query params:
     * - group_key: Filter by group (optional)
     * - page: Page number (default: 1)
     * - limit: Items per page (default: 50, max: 100)
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function listFiles(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $groupKey = $request->query->get('group_key');
        $page = max(1, (int)$request->query->get('page', 1));
        $limit = min(100, max(1, (int)$request->query->get('limit', 50)));
        $offset = ($page - 1) * $limit;

        // Build query for MessageFiles
        $qb = $this->messageFileRepository->createQueryBuilder('mf')
            ->where('mf.userId = :userId')
            ->setParameter('userId', $user->getId())
            ->orderBy('mf.createdAt', 'DESC');

        // Get total count
        $totalCount = (clone $qb)->select('COUNT(mf.id)')->getQuery()->getSingleScalarResult();

        // Get paginated results
        $messageFiles = $qb->setFirstResult($offset)
                          ->setMaxResults($limit)
                          ->getQuery()
                          ->getResult();

        $files = array_map(fn(MessageFile $mf) => [
            'id' => $mf->getId(),
            'filename' => $mf->getFileName(),
            'path' => $mf->getFilePath(),
            'file_type' => $mf->getFileType(),
            'file_size' => $mf->getFileSize(),
            'mime' => $mf->getFileMime(),
            'status' => $mf->getStatus(),
            'text_preview' => mb_substr($mf->getFileText() ?? '', 0, 200),
            'uploaded_at' => $mf->getCreatedAt(),
            'uploaded_date' => date('Y-m-d H:i:s', $mf->getCreatedAt()),
            'message_id' => $mf->getMessageId(), // null if standalone
            'is_attached' => $mf->getMessageId() !== null
        ], $messageFiles);

        return $this->json([
            'success' => true,
            'files' => $files,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $totalCount,
                'pages' => (int)ceil($totalCount / $limit)
            ]
        ]);
    }

    /**
     * Delete file
     * 
     * DELETE /api/v1/files/{id}
     */
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function deleteFile(
        int $id,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $message = $this->messageRepository->find($id);
        
        if (!$message || $message->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        // Delete physical file
        if ($message->getFilePath()) {
            $this->storageService->deleteFile($message->getFilePath());
        }

        // Delete message entity
        $this->em->remove($message);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => 'File deleted successfully'
        ]);
    }

    /**
     * Make file public and generate share link
     * 
     * POST /api/v1/files/{id}/share
     */
    #[Route('/{id}/share', name: 'share', methods: ['POST'])]
    public function makePublic(
        int $id,
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $message = $this->messageRepository->findOneBy([
            'id' => $id,
            'userId' => $user->getId(),
            'file' => 1
        ]);

        if (!$message) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        // Parse expiry from request (optional)
        $data = json_decode($request->getContent(), true);
        $expiryDays = $data['expiry_days'] ?? 7; // Default: 7 days

        // Make public
        $message->setPublic(true);
        $token = $message->generateShareToken();
        
        if ($expiryDays > 0) {
            $expiresAt = time() + ($expiryDays * 24 * 60 * 60);
            $message->setShareExpires($expiresAt);
        }

        $this->em->flush();

        $this->logger->info('File shared publicly', [
            'file_id' => $id,
            'user_id' => $user->getId(),
            'expires_at' => $message->getShareExpires()
        ]);

        return $this->json([
            'success' => true,
            'share_url' => '/up/' . $message->getFilePath(),
            'share_token' => $token,
            'expires_at' => $message->getShareExpires(),
            'is_public' => true
        ]);
    }

    /**
     * Revoke public access
     * 
     * DELETE /api/v1/files/{id}/share
     */
    #[Route('/{id}/share', name: 'unshare', methods: ['DELETE'])]
    public function revokeShare(
        int $id,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $message = $this->messageRepository->findOneBy([
            'id' => $id,
            'userId' => $user->getId(),
            'file' => 1
        ]);

        if (!$message) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        $message->revokeShare();
        $this->em->flush();

        $this->logger->info('File share revoked', [
            'file_id' => $id,
            'user_id' => $user->getId()
        ]);

        return $this->json([
            'success' => true,
            'message' => 'Share revoked',
            'is_public' => false
        ]);
    }

    /**
     * Get share info
     * 
     * GET /api/v1/files/{id}/share
     */
    #[Route('/{id}/share', name: 'share_info', methods: ['GET'])]
    public function getShareInfo(
        int $id,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $message = $this->messageRepository->findOneBy([
            'id' => $id,
            'userId' => $user->getId(),
            'file' => 1
        ]);

        if (!$message) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'is_public' => $message->isPublic(),
            'share_url' => $message->isPublic() ? '/up/' . $message->getFilePath() : null,
            'share_token' => $message->getShareToken(),
            'expires_at' => $message->getShareExpires(),
            'is_expired' => $message->isShareExpired()
        ]);
    }
}

