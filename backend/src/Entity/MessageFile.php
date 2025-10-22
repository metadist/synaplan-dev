<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\MessageFileRepository;

/**
 * MessageFile Entity
 * 
 * Stores file attachments for messages.
 * Supports multiple files per message via 1-to-N relation.
 */
#[ORM\Entity(repositoryClass: MessageFileRepository::class)]
#[ORM\Table(name: 'BMESSAGEFILES')]
#[ORM\Index(columns: ['BMESSAGEID'], name: 'idx_messagefile_message')]
#[ORM\Index(columns: ['BFILETYPE'], name: 'idx_messagefile_type')]
class MessageFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BMESSAGEID', type: 'bigint', nullable: true)]
    private ?int $messageId = null;

    #[ORM\ManyToOne(targetEntity: Message::class, inversedBy: 'files')]
    #[ORM\JoinColumn(name: 'BMESSAGEID', referencedColumnName: 'BID', nullable: true, onDelete: 'CASCADE')]
    private ?Message $message = null;

    #[ORM\Column(name: 'BFILEPATH', length: 255)]
    private string $filePath = '';

    #[ORM\Column(name: 'BFILETYPE', length: 16)]
    private string $fileType = '';

    #[ORM\Column(name: 'BFILENAME', length: 255)]
    private string $fileName = '';

    #[ORM\Column(name: 'BFILESIZE', type: 'integer')]
    private int $fileSize = 0;

    #[ORM\Column(name: 'BFILEMIME', length: 128)]
    private string $fileMime = '';

    #[ORM\Column(name: 'BFILETEXT', type: 'text')]
    private string $fileText = '';

    #[ORM\Column(name: 'BSTATUS', length: 32, options: ['default' => 'uploaded'])]
    private string $status = 'uploaded';

    #[ORM\Column(name: 'BCREATEDAT', type: 'bigint')]
    private int $createdAt;

    public function __construct()
    {
        $this->createdAt = time();
    }

    // Getters and Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessageId(): ?int
    {
        return $this->messageId;
    }

    public function setMessageId(?int $messageId): self
    {
        $this->messageId = $messageId;
        return $this;
    }

    public function getMessage(): ?Message
    {
        return $this->message;
    }

    public function setMessage(?Message $message): self
    {
        $this->message = $message;
        $this->messageId = $message?->getId();
        return $this;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function setFilePath(string $filePath): self
    {
        $this->filePath = $filePath;
        return $this;
    }

    public function getFileType(): string
    {
        return $this->fileType;
    }

    public function setFileType(string $fileType): self
    {
        $this->fileType = $fileType;
        return $this;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function setFileName(string $fileName): self
    {
        $this->fileName = $fileName;
        return $this;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function setFileSize(int $fileSize): self
    {
        $this->fileSize = $fileSize;
        return $this;
    }

    public function getFileMime(): string
    {
        return $this->fileMime;
    }

    public function setFileMime(string $fileMime): self
    {
        $this->fileMime = $fileMime;
        return $this;
    }

    public function getFileText(): string
    {
        return $this->fileText;
    }

    public function setFileText(string $fileText): self
    {
        $this->fileText = $fileText;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    public function setCreatedAt(int $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}

