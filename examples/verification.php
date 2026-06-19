<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Webhooks\HmacSha256Signer;
use Rasuvaeff\Yii3Webhooks\InMemoryNonceStorage;
use Rasuvaeff\Yii3Webhooks\ReplayGuard;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;
use Rasuvaeff\Yii3Webhooks\WebhookSignature;
use Rasuvaeff\Yii3Webhooks\WebhookVerifier;

$clock = new class implements ClockInterface {
    public function now(): DateTimeImmutable { return new DateTimeImmutable(); }
};

$signer = new HmacSha256Signer();
$verifier = new WebhookVerifier(signer: $signer, clock: $clock, toleranceSeconds: 300);
$guard = new ReplayGuard(new InMemoryNonceStorage());

$secret = 'whsec_test_secret';
$event = WebhookEvent::create(type: 'order.created', payload: '{"orderId":42}');
$timestamp = $clock->now()->getTimestamp();

// Simulate an incoming request: sender puts event ID in X-Webhook-Id header
// and HMAC signature in X-Webhook-Signature header.
$incomingEventId = $event->getId();
$incomingHeader = $signer->sign($event->getPayload(), $secret, $timestamp, $incomingEventId)->toHeaderValue();

echo "Event ID:       $incomingEventId\n";
echo "Sig header:     $incomingHeader\n\n";

$signature = WebhookSignature::fromHeaderValue($incomingHeader);
$valid = $verifier->verify(payload: $event->getPayload(), secret: $secret, signature: $signature, eventId: $incomingEventId);

echo 'Signature valid: ' . ($valid ? 'yes' : 'no') . "\n";

if ($valid) {
    try {
        // Use the event ID as the nonce — it uniquely identifies the delivery
        // and allows replay detection before (or independently of) signature verification.
        $guard->accept($incomingEventId);
        echo "Nonce accepted — processing event\n";
    } catch (\RuntimeException $e) {
        echo "REPLAY DETECTED: {$e->getMessage()}\n";
    }
}

// Simulate replay of the exact same request
echo "\nSimulating replay...\n";

$valid2 = $verifier->verify(payload: $event->getPayload(), secret: $secret, signature: $signature, eventId: $incomingEventId);

if ($valid2) {
    try {
        $guard->accept($incomingEventId);
        echo "Accepted (should not happen)\n";
    } catch (\RuntimeException $e) {
        echo "REPLAY BLOCKED: {$e->getMessage()}\n";
    }
}
