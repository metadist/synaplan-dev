<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\Widget;
use App\Repository\WidgetRepository;
use App\Repository\PromptRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Widget Management Service
 * 
 * Handles widget CRUD operations and embed code generation
 */
class WidgetService
{
    public function __construct(
        private EntityManagerInterface $em,
        private WidgetRepository $widgetRepository,
        private PromptRepository $promptRepository,
        private RateLimitService $rateLimitService,
        private LoggerInterface $logger
    ) {}

    /**
     * Create a new widget
     */
    public function createWidget(User $owner, string $name, string $taskPromptTopic, array $config = []): Widget
    {
        // Validate task prompt exists
        $prompt = $this->promptRepository->findByTopic($taskPromptTopic, $owner->getId());
        if (!$prompt) {
            throw new \InvalidArgumentException('Task prompt not found: ' . $taskPromptTopic);
        }

        $widget = new Widget();
        $widget->setOwner($owner);
        $widget->setName($name);
        $widget->setTaskPromptTopic($taskPromptTopic);
        $widget->setConfig($this->sanitizeConfig($config));

        $this->em->persist($widget);
        $this->em->flush();

        $this->logger->info('Widget created', [
            'widget_id' => $widget->getWidgetId(),
            'owner_id' => $owner->getId(),
            'task_prompt' => $taskPromptTopic
        ]);

        return $widget;
    }

    /**
     * Update widget configuration
     */
    public function updateWidget(Widget $widget, array $config): void
    {
        $widget->setConfig($this->sanitizeConfig($config));
        $widget->touch();
        $this->em->flush();

        $this->logger->info('Widget updated', [
            'widget_id' => $widget->getWidgetId()
        ]);
    }

    /**
     * Update widget name
     */
    public function updateWidgetName(Widget $widget, string $name): void
    {
        $widget->setName($name);
        $widget->touch();
        $this->em->flush();
    }

    /**
     * Delete widget
     */
    public function deleteWidget(Widget $widget): void
    {
        $widgetId = $widget->getWidgetId();
        $this->em->remove($widget);
        $this->em->flush();

        $this->logger->info('Widget deleted', [
            'widget_id' => $widgetId
        ]);
    }

    /**
     * Get widget by widgetId
     */
    public function getWidgetById(string $widgetId): ?Widget
    {
        return $this->widgetRepository->findOneByWidgetId($widgetId);
    }

    /**
     * List all widgets for a user
     */
    public function listWidgetsByOwner(User $owner): array
    {
        return $this->widgetRepository->findByOwnerId($owner->getId());
    }

    /**
     * Generate embed code for a widget
     */
    public function generateEmbedCode(Widget $widget, string $baseUrl): string
    {
        $widgetId = $widget->getWidgetId();
        $config = $widget->getConfig();

        // Extract configuration
        $position = $config['position'] ?? 'bottom-right';
        $primaryColor = $config['primaryColor'] ?? '#007bff';
        $iconColor = $config['iconColor'] ?? '#ffffff';
        $theme = $config['defaultTheme'] ?? 'light';
        $autoOpen = $config['autoOpen'] ?? false;
        $autoOpenStr = $autoOpen ? 'true' : 'false';
        $autoMessage = $config['autoMessage'] ?? 'Hello! How can I help you today?';

        return <<<HTML
<!-- Synaplan Chat Widget -->
<script src="{$baseUrl}/widget.js"></script>
<script>
  SynaplanWidget.init({
    widgetId: '{$widgetId}',
    position: '{$position}',
    primaryColor: '{$primaryColor}',
    iconColor: '{$iconColor}',
    defaultTheme: '{$theme}',
    autoOpen: {$autoOpenStr},
    autoMessage: '{$autoMessage}',
    apiUrl: '{$baseUrl}'
  });
</script>
HTML;
    }

    /**
     * Generate WordPress shortcode
     */
    public function generateWordPressShortcode(Widget $widget): string
    {
        return sprintf('[synaplan_widget id="%s"]', $widget->getWidgetId());
    }

    /**
     * Check if widget is active (owner limits not exceeded)
     */
    public function isWidgetActive(Widget $widget): bool
    {
        if (!$widget->isActive()) {
            return false;
        }

        $owner = $widget->getOwner();
        if (!$owner) {
            $owner = $this->em->find(User::class, $widget->getOwnerId());
        }

        if (!$owner instanceof User) {
            $this->logger->warning('Widget owner not found', [
                'widget_id' => $widget->getWidgetId(),
                'owner_id' => $widget->getOwnerId()
            ]);
            return false;
        }

        // Check owner's usage limits for messages
        $limitCheck = $this->rateLimitService->checkLimit($owner, 'MESSAGES');

        if (!($limitCheck['allowed'] ?? true)) {
            $this->logger->warning('Widget owner rate limit exceeded', [
                'widget_id' => $widget->getWidgetId(),
                'owner_id' => $owner->getId(),
                'remaining' => $limitCheck['remaining'] ?? 0
            ]);
            return false;
        }

        return true;
    }

    /**
     * Sanitize and validate widget configuration
     */
    private function sanitizeConfig(array $config): array
    {
        $defaults = [
            'position' => 'bottom-right',
            'primaryColor' => '#007bff',
            'iconColor' => '#ffffff',
            'defaultTheme' => 'light',
            'autoOpen' => false,
            'autoMessage' => 'Hello! How can I help you today?',
            'messageLimit' => 50,
            'maxFileSize' => 10
        ];

        // Merge with defaults
        $config = array_merge($defaults, $config);

        // Validate position
        $validPositions = ['bottom-left', 'bottom-right', 'top-left', 'top-right'];
        if (!in_array($config['position'], $validPositions)) {
            $config['position'] = 'bottom-right';
        }

        // Validate colors
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $config['primaryColor'])) {
            $config['primaryColor'] = '#007bff';
        }
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $config['iconColor'])) {
            $config['iconColor'] = '#ffffff';
        }

        // Validate theme
        if (!in_array($config['defaultTheme'], ['light', 'dark'])) {
            $config['defaultTheme'] = 'light';
        }

        // Validate limits
        $config['messageLimit'] = max(1, min(100, (int)$config['messageLimit']));
        $config['maxFileSize'] = max(1, min(50, (int)$config['maxFileSize']));

        return $config;
    }
}

