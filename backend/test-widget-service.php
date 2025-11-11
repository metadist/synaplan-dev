<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

(new Dotenv())->bootEnv(__DIR__.'/.env');

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$container = $kernel->getContainer();

$widgetService = $container->get(\App\Service\WidgetService::class);
$em = $container->get('doctrine')->getManager();
$widgetRepo = $em->getRepository(\App\Entity\Widget::class);

$widgetId = 'wdg_03ab6ff162017137c380e6bd34502e89';
$widget = $widgetRepo->findOneBy(['widgetId' => $widgetId]);

if (!$widget) {
    die("Widget not found\n");
}

echo "=== BEFORE UPDATE (via WidgetService) ===\n";
echo "Config: " . json_encode($widget->getConfig(), JSON_PRETTY_PRINT) . "\n";
echo "AllowedDomains property: " . json_encode($widget->getAllowedDomains(), JSON_PRETTY_PRINT) . "\n\n";

// Update via WidgetService (like the controller does)
$incomingConfig = [
    'allowedDomains' => ['via-service.com', 'test.de']
];

echo "Calling widgetService->updateWidget with: " . json_encode($incomingConfig, JSON_PRETTY_PRINT) . "\n\n";

$widgetService->updateWidget($widget, $incomingConfig);

echo "=== AFTER widgetService->updateWidget ===\n";
echo "Config: " . json_encode($widget->getConfig(), JSON_PRETTY_PRINT) . "\n";
echo "AllowedDomains property: " . json_encode($widget->getAllowedDomains(), JSON_PRETTY_PRINT) . "\n\n";

// Reload from DB
$em->clear();
$widget = $widgetRepo->findOneBy(['widgetId' => $widgetId]);

echo "=== AFTER RELOAD FROM DB ===\n";
echo "Config: " . json_encode($widget->getConfig(), JSON_PRETTY_PRINT) . "\n";
echo "AllowedDomains property: " . json_encode($widget->getAllowedDomains(), JSON_PRETTY_PRINT) . "\n";

