<?php

namespace App\Service\File;

use App\AI\Service\AiFacade;
use Psr\Log\LoggerInterface;

/**
 * Universal File Processor
 * 
 * Extracts text from files using multiple strategies:
 * 1. Native (plain text files)
 * 2. Tika (documents: PDF, DOCX, XLSX, etc.)
 * 3. Rasterize + Vision AI (fallback for PDFs with low-quality Tika extraction)
 * 4. Vision AI (images)
 * 5. Speech-to-Text (audio/video files)
 * 
 * Strategy from legacy: Native -> Tika -> Rasterize+Vision fallback
 */
class FileProcessor
{
    private const PLAIN_TEXT_MIMES = [
        'text/plain',
        'text/markdown',
        'text/x-markdown',
        'text/csv',
        'text/html',
    ];

    private const PDF_MIMES = [
        'application/pdf',
        'application/x-pdf',
    ];

    private const IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    private const OFFICE_EXT_TO_MIME = [
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'csv'  => 'text/csv',
        'md'   => 'text/markdown',
        'html' => 'text/html',
        'htm'  => 'text/html',
    ];

    public function __construct(
        private TikaClient $tikaClient,
        private PdfRasterizer $rasterizer,
        private TextCleaner $textCleaner,
        private AiFacade $aiFacade,
        private LoggerInterface $logger,
        private string $uploadDir,
        private int $tikaMinLength,
        private float $tikaMinEntropy
    ) {}

    /**
     * Extract text from a file
     * 
     * @param string $relativePath Relative path to file (from upload dir)
     * @param string $fileExtension File extension (e.g. 'pdf', 'docx')
     * @param int|null $userId User ID for Vision AI fallback
     * @return array [extractedText, meta] where meta contains strategy, mime, ext, etc.
     */
    public function extractText(string $relativePath, string $fileExtension, ?int $userId = null): array
    {
        $absolutePath = $this->resolveAbsolutePath($relativePath);
        $mime = mime_content_type($absolutePath) ?: '';
        $ext = strtolower($fileExtension);
        $mime = $this->ensureOfficeMime($mime, $ext);

        $meta = [
            'mime' => $mime,
            'ext' => $ext,
            'file' => basename($absolutePath),
        ];

        $this->logger->info('FileProcessor: Starting extraction', $meta);

        // Strategy 1: Native plain text
        if ($this->isPlainTextMime($mime)) {
            $text = @file_get_contents($absolutePath) ?: '';
            $text = $this->textCleaner->clean($text);
            
            $this->logger->info('FileProcessor: Native text extraction', [
                'strategy' => 'native_text',
                'bytes' => strlen($text)
            ]);

            return [$text, ['strategy' => 'native_text'] + $meta];
        }

        // Strategy 2: Image files -> Vision AI
        if ($this->isImageMime($mime)) {
            return $this->extractFromImage($relativePath, $userId, $meta);
        }

        // Strategy 3: Tika for documents
        if (!$this->tikaClient->isEnabled()) {
            // Tika disabled: Only support PDFs via vision
            if ($this->isPdfMime($mime) || $ext === 'pdf') {
                return $this->extractFromPdfViaVision($absolutePath, $userId, $meta);
            }
            
            $this->logger->warning('FileProcessor: Tika disabled, cannot extract', $meta);
            return ['', ['strategy' => 'tika_disabled'] + $meta];
        }

        // Try Tika first for documents
        [$tikaText, $tikaMeta] = $this->tikaClient->extractText($absolutePath, $mime);
        
        if (is_string($tikaText)) {
            $tikaText = $this->textCleaner->clean($tikaText);
            $isPdf = $this->isPdfMime($mime) || $ext === 'pdf';
            
            // Check quality for PDFs
            $lowQuality = $isPdf 
                ? $this->textCleaner->isLowQuality($tikaText, $this->tikaMinLength, $this->tikaMinEntropy)
                : false;

            if (mb_strlen(trim($tikaText)) > 0 && !$lowQuality) {
                $this->logger->info('FileProcessor: Tika extraction success', [
                    'strategy' => 'tika',
                    'bytes' => strlen($tikaText)
                ]);

                return [$tikaText, ['strategy' => 'tika'] + $meta + $tikaMeta];
            }

            // Low-quality Tika output for PDF -> fallback to vision
            if ($isPdf) {
                $this->logger->info('FileProcessor: Tika quality too low, trying vision fallback');
                return $this->extractFromPdfViaVision($absolutePath, $userId, $meta);
            }
        }

        // Tika failed or produced unusable output
        $this->logger->warning('FileProcessor: Extraction failed', ['strategy' => 'tika_failed'] + $meta);
        return ['', ['strategy' => 'tika_failed'] + $meta];
    }

    /**
     * Extract text from PDF using rasterization + Vision AI
     */
    private function extractFromPdfViaVision(string $absolutePath, ?int $userId, array $baseMeta): array
    {
        $images = $this->rasterizer->pdfToPng($absolutePath);
        
        if (empty($images)) {
            $this->logger->warning('FileProcessor: PDF rasterization failed');
            return ['', ['strategy' => 'rasterize_failed'] + $baseMeta];
        }

        $this->logger->info('FileProcessor: PDF rasterized', ['pages' => count($images)]);

        $fullText = $this->aggregateVisionResults($images, $userId);
        $fullText = $this->textCleaner->clean($fullText);

        if (mb_strlen(trim($fullText)) > 0) {
            $this->logger->info('FileProcessor: Vision extraction success', [
                'strategy' => 'rasterize_vision',
                'pages' => count($images),
                'bytes' => strlen($fullText)
            ]);

            return [$fullText, [
                'strategy' => 'rasterize_vision',
                'pages' => count($images),
                'engine' => $this->rasterizer->getLastEngine(),
            ] + $baseMeta];
        }

        return ['', ['strategy' => 'rasterize_vision'] + $baseMeta];
    }

    /**
     * Extract text from image using Vision AI
     */
    private function extractFromImage(string $relativePath, ?int $userId, array $baseMeta): array
    {
        $this->logger->info('FileProcessor: Using Vision AI for image');

        try {
            // Use Vision AI provider
            $prompt = 'Describe this image and extract any visible text.';
            $result = $this->aiFacade->analyzeImage($relativePath, $prompt, $userId);
            
            $text = $result['content'] ?? '';
            $text = $this->textCleaner->clean($text);

            $this->logger->info('FileProcessor: Vision AI extraction', [
                'strategy' => 'vision_ai',
                'bytes' => strlen($text)
            ]);

            return [$text, ['strategy' => 'vision_ai', 'provider' => $result['provider'] ?? 'unknown'] + $baseMeta];
        } catch (\Throwable $e) {
            $this->logger->error('FileProcessor: Vision AI failed', [
                'error' => $e->getMessage()
            ]);
            return ['', ['strategy' => 'vision_failed', 'error' => $e->getMessage()] + $baseMeta];
        }
    }

    /**
     * Aggregate Vision AI results from multiple images (PDF pages)
     */
    private function aggregateVisionResults(array $imagePaths, ?int $userId): string
    {
        $fullText = '';

        foreach ($imagePaths as $imgPath) {
            $relativePath = $this->absoluteToRelative($imgPath);
            
            try {
                $prompt = 'Extract all text from this PDF page. List text content in reading order.';
                $result = $this->aiFacade->analyzeImage($relativePath, $prompt, $userId);
                $text = $result['content'] ?? '';

                if (!empty($text)) {
                    if (strlen($fullText) > 0) {
                        $fullText .= "\n\n";
                    }
                    $fullText .= $text;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('FileProcessor: Vision AI failed for page', [
                    'image' => basename($imgPath),
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $fullText;
    }

    /**
     * Resolve absolute file path from relative path
     */
    private function resolveAbsolutePath(string $relativePath): string
    {
        $uploadBase = rtrim($this->uploadDir, '/') . '/';
        $candidates = [
            $uploadBase . $relativePath,
            $relativePath, // Already absolute?
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        $this->logger->warning('FileProcessor: File not found', [
            'relative' => $relativePath,
            'tried' => $candidates
        ]);

        return $candidates[0]; // Return first candidate even if not found
    }

    /**
     * Convert absolute path to relative (from upload dir)
     */
    private function absoluteToRelative(string $absolutePath): string
    {
        $uploadBase = rtrim($this->uploadDir, '/') . '/';
        
        if (str_starts_with($absolutePath, $uploadBase)) {
            return substr($absolutePath, strlen($uploadBase));
        }

        return basename($absolutePath);
    }

    /**
     * Check if MIME type is plain text
     */
    private function isPlainTextMime(string $mime): bool
    {
        return in_array($mime, self::PLAIN_TEXT_MIMES, true);
    }

    /**
     * Check if MIME type is PDF
     */
    private function isPdfMime(string $mime): bool
    {
        return in_array($mime, self::PDF_MIMES, true);
    }

    /**
     * Check if MIME type is image
     */
    private function isImageMime(string $mime): bool
    {
        return in_array($mime, self::IMAGE_MIMES, true);
    }

    /**
     * Ensure correct MIME type for Office documents
     * (Some environments report XLSX/DOCX as application/zip)
     */
    private function ensureOfficeMime(string $mime, string $ext): string
    {
        if (isset(self::OFFICE_EXT_TO_MIME[$ext])) {
            if (empty($mime) || $mime === 'application/zip' || $mime === 'application/octet-stream') {
                return self::OFFICE_EXT_TO_MIME[$ext];
            }
        }

        return $mime;
    }
}

