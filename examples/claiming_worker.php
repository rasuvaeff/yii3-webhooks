<?php

declare(strict_types=1);

/**
 * Example: two workers polling one storage.
 *
 * findPending() hands the same deliveries to everyone who asks, so with more
 * than one worker the receiver gets the same event twice. A storage that
 * implements ClaimingDeliveryStorage leases each delivery to exactly one
 * worker, and skips the ones still waiting out their backoff.
 */

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\Yii3Webhooks\ClaimingDeliveryStorage;
use Rasuvaeff\Yii3Webhooks\InMemoryDeliveryStorage;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStorage;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;
use Rasuvaeff\Yii3Webhooks\WebhookRetryPolicy;

$storage = new InMemoryDeliveryStorage();
$policy = WebhookRetryPolicy::exponential(maxAttempts: 5, baseSeconds: 60, cap: 3600);
$now = new DateTimeImmutable('2026-08-22 12:00:00');
$endpoint = new WebhookEndpoint(url: 'https://partner.example.com/webhook', secret: 'whsec_test');

// Ready: never attempted.
$storage->save(WebhookDelivery::create(
    event: WebhookEvent::create(type: 'order.created', payload: '{"orderId":1}'),
    endpoint: $endpoint,
    createdAt: $now->modify('-10 minutes'),
    id: 'fresh',
));

// Not ready: one attempt two seconds ago, and the policy asks for 60.
$storage->save(WebhookDelivery::create(
    event: WebhookEvent::create(type: 'order.created', payload: '{"orderId":2}'),
    endpoint: $endpoint,
    createdAt: $now->modify('-1 hour'),
    id: 'backing-off',
)->withAttempt(at: $now->modify('-2 seconds'), error: 'HTTP 503'));

/**
 * What a worker iteration looks like — the fallback keeps it working against a
 * storage that cannot claim.
 *
 * @return list<WebhookDelivery>
 */
$poll = static function (WebhookDeliveryStorage $storage, DateTimeImmutable $now) use ($policy): array {
    if ($storage instanceof ClaimingDeliveryStorage) {
        return $storage->claimReady(
            now: $now,
            readyThresholds: $policy->readyThresholds($now),
            maxAttempts: $policy->getMaxAttempts(),
            leaseSeconds: 300,
            limit: 100,
        );
    }

    return array_values(array_filter(
        $storage->findPending(),
        static fn(WebhookDelivery $delivery): bool => $policy->isReadyForRetry($delivery, $now),
    ));
};

$first = $poll($storage, $now);
$second = $poll($storage, $now);

echo 'Worker A claimed: ' . implode(', ', array_map(
    static fn(WebhookDelivery $delivery): string => $delivery->getId(),
    $first,
)) . "\n";
echo 'Worker B claimed: ' . (count($second) === 0 ? '(nothing)' : implode(', ', array_map(
    static fn(WebhookDelivery $delivery): string => $delivery->getId(),
    $second,
))) . "\n";
echo 'findPending() would have handed both workers: ' . count($storage->findPending()) . " deliveries\n\n";

// A retryable failure: record the attempt and hand the lease back, so the
// delivery reappears as soon as its backoff elapses instead of after the lease.
$failed = $first[0]->withAttempt(at: $now, error: 'HTTP 502');
$storage->save($failed);
$storage->releaseClaim($failed);

echo "After releasing the claim with one attempt recorded:\n";
echo '  ready now: ' . count($poll($storage, $now)) . "\n";
echo '  ready in 61 seconds: ' . count($poll($storage, $now->modify('+61 seconds'))) . "\n";
