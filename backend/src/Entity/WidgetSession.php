<?php

namespace App\Entity;

use App\Repository\WidgetSessionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WidgetSessionRepository::class)]
#[ORM\Table(name: 'BWIDGET_SESSIONS')]
#[ORM\UniqueConstraint(name: 'uk_widget_session', columns: ['BWIDGETID', 'BSESSIONID'])]
#[ORM\Index(columns: ['BWIDGETID'], name: 'idx_session_widget')]
#[ORM\Index(columns: ['BEXPIRES'], name: 'idx_session_expires')]
class WidgetSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BWIDGETID', length: 64)]
    private string $widgetId;

    #[ORM\Column(name: 'BSESSIONID', length: 64)]
    private string $sessionId;

    #[ORM\Column(name: 'BMESSAGECOUNT', type: 'integer')]
    private int $messageCount = 0;

    #[ORM\Column(name: 'BLASTMESSAGE', type: 'bigint')]
    private int $lastMessage = 0;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BEXPIRES', type: 'bigint')]
    private int $expires;

    public function __construct()
    {
        $this->created = time();
        $this->expires = time() + 86400; // 24 hours
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWidgetId(): string
    {
        return $this->widgetId;
    }

    public function setWidgetId(string $widgetId): self
    {
        $this->widgetId = $widgetId;
        return $this;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function setSessionId(string $sessionId): self
    {
        $this->sessionId = $sessionId;
        return $this;
    }

    public function getMessageCount(): int
    {
        return $this->messageCount;
    }

    public function setMessageCount(int $messageCount): self
    {
        $this->messageCount = $messageCount;
        return $this;
    }

    public function incrementMessageCount(): self
    {
        $this->messageCount++;
        return $this;
    }

    public function getLastMessage(): int
    {
        return $this->lastMessage;
    }

    public function setLastMessage(int $lastMessage): self
    {
        $this->lastMessage = $lastMessage;
        $this->expires = $lastMessage + 86400; // Extend expiry by 24h
        return $this;
    }

    public function updateLastMessage(): self
    {
        return $this->setLastMessage(time());
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function setCreated(int $created): self
    {
        $this->created = $created;
        return $this;
    }

    public function getExpires(): int
    {
        return $this->expires;
    }

    public function setExpires(int $expires): self
    {
        $this->expires = $expires;
        return $this;
    }

    public function isExpired(): bool
    {
        return time() > $this->expires;
    }

    /**
     * Get messages sent in the last minute
     */
    public function getMessagesInLastMinute(): int
    {
        // This will be tracked in a separate table or cache
        // For now, we'll implement rate limiting in the service
        return 0;
    }
}

