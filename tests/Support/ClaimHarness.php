<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests\Support;

use DateTimeImmutable;
use Rasuvaeff\Yii3Webhooks\InMemoryDeliveryStorage;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;

/**
 * The system-under-test side of the claim lifecycle property test: a storage,
 * the worker's own copy of the delivery (reassigned on every step, since
 * {@see WebhookDelivery} is immutable), and the clock the workers read.
 *
 * The two counters let the test body gate on the branches that matter — a
 * lease actually handed out, and a lease actually refused — instead of trusting
 * that a random sequence reached both.
 */
final class ClaimHarness
{
    public int $claimsGranted = 0;
    public int $claimsRefused = 0;

    public function __construct(
        public readonly InMemoryDeliveryStorage $storage,
        public WebhookDelivery $delivery,
        public DateTimeImmutable $now,
    ) {}
}
