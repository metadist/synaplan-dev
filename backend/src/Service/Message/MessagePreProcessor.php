<?php

namespace App\Service\Message;

use App\Entity\Message;
use App\Entity\MessageFile;
use App\Repository\MessageRepository;
use App\Service\WhisperService;
use App\AI\Service\AiFacade;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * PreProcessor für eingehende Nachrichten
 * 
 * Tasks:
 * - File Download (von WhatsApp, Upload, etc.)
 * - File Parsing (Tika, Whisper, Vision AI)
 * - Message Metadata extraction
 */
class MessagePreProcessor
{
    public function __construct(
        private MessageRepository $messageRepository,
        private HttpClientInterface $httpClient,
        private WhisperService $whisperService,
        private AiFacade $aiFacade,
        private LoggerInterface $logger,
        private string $tikaBaseUrl,
        private string $uploadsDir
    ) {}

    /**
     * Prozessiert Message (Files downloaden, parsen, etc.)
     */
    public function process(Message $message, callable $progressCallback = null): Message
    {
        // Check for legacy single file
        $hasLegacyFile = $message->getFile() > 0 && $message->getFilePath();
        
        // Check for new multiple files (MessageFile entities)
        $messageFiles = $message->getFiles();
        $hasNewFiles = $messageFiles->count() > 0;
        
        $this->logger->info('PreProcessor: Starting processing', [
            'message_id' => $message->getId(),
            'has_legacy_file' => $hasLegacyFile,
            'new_files_count' => $messageFiles->count()
        ]);
        
        if ($hasLegacyFile) {
            $this->notify($progressCallback, 'preprocessing', 'Processing file...');
            $this->processFile($message);
            $this->notify($progressCallback, 'preprocessing', 'File processing complete.');
        }
        
        // Process new multiple files (MessageFile entities)
        if ($hasNewFiles) {
            $this->logger->info('PreProcessor: Processing multiple files', [
                'count' => $messageFiles->count()
            ]);
            
            $this->notify($progressCallback, 'preprocessing', "Processing {$messageFiles->count()} file(s)...");
            $processed = 0;
            foreach ($messageFiles as $messageFile) {
                $this->logger->info('PreProcessor: Processing file', [
                    'file_id' => $messageFile->getId(),
                    'filename' => $messageFile->getFileName()
                ]);
                
                $this->processMessageFile($messageFile);
                $processed++;
                $this->notify($progressCallback, 'preprocessing', "Processed $processed/{$messageFiles->count()} files");
            }
            $this->notify($progressCallback, 'preprocessing', 'All files processed.');
            
            // CRITICAL: Persist changes to MessageFile entities!
            $this->messageRepository->save($message);
        } else {
            $this->logger->warning('PreProcessor: No files to process', [
                'message_id' => $message->getId()
            ]);
        }

        $this->messageRepository->save($message);

        return $message;
    }

    /**
     * Process a MessageFile entity (NEW: multiple files support)
     */
    private function processMessageFile(MessageFile $messageFile): void
    {
        $filePath = $messageFile->getFilePath();
        $fileType = strtolower($messageFile->getFileType());

        // File existiert lokal?
        $fullPath = $this->uploadsDir . '/' . $filePath;
        if (!file_exists($fullPath)) {
            $this->logger->warning("MessageFile not found: {$fullPath}");
            $messageFile->setStatus('error');
            return;
        }

        $this->logger->info('PreProcessor: Processing MessageFile', [
            'file_id' => $messageFile->getId(),
            'type' => $fileType,
            'size' => $messageFile->getFileSize()
        ]);

        // Parse File mit Tika (für PDFs, DOCX, etc.)
        if (in_array($fileType, ['pdf', 'docx', 'doc', 'xlsx', 'xls', 'pptx', 'txt'])) {
            $text = $this->parseWithTika($fullPath);
            if ($text) {
                $messageFile->setFileText($text);
                $messageFile->setStatus('processed');
                $this->logger->info('PreProcessor: Document parsed', [
                    'file_id' => $messageFile->getId(),
                    'text_length' => strlen($text)
                ]);
            }
        }

        // Audio mit Whisper
        elseif (in_array($fileType, ['ogg', 'mp3', 'wav', 'm4a', 'opus', 'flac', 'webm'])) {
            try {
                $result = $this->transcribeWithWhisper($fullPath, null);
                if ($result && !empty($result['text'])) {
                    $messageFile->setFileText($result['text']);
                    $messageFile->setStatus('processed');
                    $this->logger->info('PreProcessor: Audio transcribed', [
                        'file_id' => $messageFile->getId(),
                        'text_length' => strlen($result['text']),
                        'language' => $result['language']
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->error('PreProcessor: Audio transcription failed', [
                    'file_id' => $messageFile->getId(),
                    'error' => $e->getMessage()
                ]);
                $messageFile->setStatus('error');
            }
        }

        // Image mit Vision AI
        elseif (in_array($fileType, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
            try {
                // Get userId from message relation
                $userId = $messageFile->getMessage()?->getUserId() ?? 0;
                $text = $this->processImageWithVision($messageFile->getFilePath(), $userId);
                if ($text) {
                    $messageFile->setFileText($text);
                    $messageFile->setStatus('processed');
                    $this->logger->info('PreProcessor: Image processed with Vision AI', [
                        'file_id' => $messageFile->getId(),
                        'text_length' => strlen($text)
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->error('PreProcessor: Vision AI failed', [
                    'file_id' => $messageFile->getId(),
                    'error' => $e->getMessage()
                ]);
                $messageFile->setStatus('error');
            }
        }
    }

    /**
     * Legacy: Process file attached directly to Message (OLD format)
     */
    private function processFile(Message $message): void
    {
        $filePath = $message->getFilePath();
        $fileType = strtolower($message->getFileType());

        // File existiert lokal?
        $fullPath = $this->uploadsDir . '/' . $filePath;
        if (!file_exists($fullPath)) {
            $this->logger->warning("File not found: {$fullPath}");
            return;
        }

        // Parse File mit Tika (für PDFs, DOCX, etc.)
        if (in_array($fileType, ['pdf', 'docx', 'doc', 'xlsx', 'xls', 'pptx', 'txt'])) {
            $this->logger->info('PreProcessor: Parsing document with Tika', [
                'file' => basename($fullPath),
                'type' => $fileType
            ]);
            
            $text = $this->parseWithTika($fullPath);
            if ($text) {
                $message->setFileText($text);
                $this->logger->info('PreProcessor: Document parsed successfully', [
                    'text_length' => strlen($text)
                ]);
            }
        }

        // Audio mit Whisper
        if (in_array($fileType, ['ogg', 'mp3', 'wav', 'm4a', 'opus', 'flac', 'webm'])) {
            if (!$this->whisperService->isAvailable()) {
                $this->logger->warning('PreProcessor: Whisper not available, skipping audio transcription', [
                    'file' => basename($fullPath)
                ]);
                return;
            }

            $this->logger->info('PreProcessor: Transcribing audio with Whisper', [
                'file' => basename($fullPath),
                'type' => $fileType
            ]);

            try {
                $result = $this->transcribeWithWhisper($fullPath, $message->getLanguage());
                if ($result && !empty($result['text'])) {
                    $message->setFileText($result['text']);
                    
                    // Update detected language if different
                    if ($result['language'] !== 'unknown' && $result['language'] !== $message->getLanguage()) {
                        $message->setLanguage($result['language']);
                    }
                    
                    $this->logger->info('PreProcessor: Audio transcribed successfully', [
                        'text_length' => strlen($result['text']),
                        'detected_language' => $result['language'],
                        'duration' => $result['duration'] . 's'
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->error('PreProcessor: Audio transcription failed', [
                    'file' => basename($fullPath),
                    'error' => $e->getMessage()
                ]);
                // Don't fail the entire process, just skip transcription
            }
        }

        // Image mit Vision AI (wenn Tika nichts extrahiert hat)
        if (in_array($fileType, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
            $this->logger->info('PreProcessor: Processing image with Vision AI', [
                'file' => basename($fullPath),
                'type' => $fileType
            ]);
            
            try {
                $text = $this->processImageWithVision($message->getFilePath(), $message->getUserId());
                if ($text) {
                    $message->setFileText($text);
                    $this->logger->info('PreProcessor: Image processed successfully', [
                        'text_length' => strlen($text)
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->error('PreProcessor: Vision AI failed', [
                    'file' => basename($fullPath),
                    'error' => $e->getMessage()
                ]);
                // Don't fail the entire process, just skip vision analysis
            }
        }
    }

    /**
     * Parse File mit Apache Tika
     */
    private function parseWithTika(string $filePath): ?string
    {
        try {
            // Tika Server: PUT /tika
            $response = $this->httpClient->request('PUT', $this->tikaBaseUrl . '/tika', [
                'headers' => [
                    'Accept' => 'text/plain',
                ],
                'body' => fopen($filePath, 'r'),
                'timeout' => 30,
            ]);

            if ($response->getStatusCode() === 200) {
                $text = $response->getContent();
                return trim($text);
            }
        } catch (\Exception $e) {
            $this->logger->error("Tika parsing failed: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Transcribe audio file with Whisper
     */
    private function transcribeWithWhisper(string $filePath, ?string $languageHint = null): ?array
    {
        try {
            $options = [];
            
            // Use language hint if available (speeds up transcription)
            if ($languageHint && strlen($languageHint) === 2) {
                $options['language'] = $languageHint;
            }
            
            // Use base model by default (good balance of speed/accuracy)
            $options['model'] = 'base';
            
            return $this->whisperService->transcribe($filePath, $options);
        } catch (\Exception $e) {
            $this->logger->error("Whisper transcription failed: {$e->getMessage()}", [
                'file' => basename($filePath),
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Process image with Vision AI
     */
    private function processImageWithVision(string $relativePath, int $userId): ?string
    {
        try {
            $prompt = 'Describe this image in detail and extract any visible text. If there is text in the image, transcribe it exactly.';
            
            $result = $this->aiFacade->analyzeImage($relativePath, $prompt, $userId);
            
            return $result['content'] ?? null;
        } catch (\Exception $e) {
            $this->logger->error("Vision AI analysis failed: {$e->getMessage()}", [
                'file' => basename($relativePath),
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function notify(?callable $callback, string $status, string $message): void
    {
        if ($callback) {
            $callback([
                'status' => $status,
                'message' => $message,
                'timestamp' => time(),
            ]);
        }
    }
}

