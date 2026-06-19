<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use DateTimeImmutable;
use Rasuvaeff\Yii3Webhooks\InMemoryDeliveryStorage;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;
use Rasuvaeff\Yii3Webhooks\WebhookRetryPolicy;

$event = WebhookEvent::create(type: 'order.created', payload: '{"orderId":42}');
$endpoint = new WebhookEndpoint(url: 'https://partner.example.com/webhook', secret: 'secret');
$storage = new InMemoryDeliveryStorage();
$policy = WebhookRetryPolicy::fixed(maxAttempts: 3, delaySeconds: 0);
$now = new DateTimeImmutable();

$delivery = WebhookDelivery::create(event: $event, endpoint: $endpoint, createdAt: $now);
$storage->save($delivery);

echo "Created delivery: {$delivery->getId()}\n";
echo "Status: {$delivery->getStatus()->value}, Attempts: {$delivery->getAttempts()}\n\n";

// Simulate three consecutive failed attempts (maxAttempts = 3)
for ($i = 1; $i <= 3; $i++) {
    $delivery = $delivery->withAttempt($now, error: 'Connection refused');
    $storage->save($delivery);

    echo "After attempt $i:\n";
    echo "  Status: {$delivery->getStatus()->value}, Attempts: {$delivery->getAttempts()}\n";
    echo "  Error: {$delivery->getLastError()}\n";
    echo "  Should retry: " . ($policy->shouldRetry($delivery) ? 'yes' : 'no') . "\n";
    echo "  Ready now (delay=0): " . ($policy->isReadyForRetry($delivery, $now) ? 'yes' : 'no') . "\n\n";
}

// Retries exhausted — caller must explicitly mark the delivery as failed.
// Without this step the delivery stays Pending and findPending() keeps returning it.
$storage->markFailed($delivery);

$final = $storage->getById($delivery->getId());
echo "Final status: {$final?->getStatus()->value}\n";
echo "Total attempts: {$final?->getAttempts()}\n";
echo "Pending count: " . count($storage->findPending()) . "\n";
