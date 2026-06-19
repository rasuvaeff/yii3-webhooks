<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Webhooks\HmacSha256Signer;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;

$event = WebhookEvent::create(
    type: 'order.created',
    payload: json_encode(['orderId' => 42, 'total' => 99.95], JSON_THROW_ON_ERROR),
);

$endpoint = new WebhookEndpoint(
    url: 'https://partner.example.com/webhook',
    secret: 'whsec_test_secret',
    headers: ['X-Tenant-Id' => 'tenant-1'],
);

$clock = new class implements ClockInterface {
    public function now(): DateTimeImmutable { return new DateTimeImmutable(); }
};

$signer = new HmacSha256Signer();
$timestamp = $clock->now()->getTimestamp();

$signature = $signer->sign(
    payload: $event->getPayload(),
    secret: $endpoint->getSecret(),
    timestamp: $timestamp,
    eventId: $event->getId(),
);

echo "Event ID:  {$event->getId()}\n";
echo "Event type: {$event->getType()}\n";
echo "Payload:   {$event->getPayload()}\n\n";
echo "Signature header: {$signature->toHeaderValue()}\n";
echo "Timestamp:  {$signature->getTimestamp()}\n";
echo "HMAC value: {$signature->getValue()}\n";
