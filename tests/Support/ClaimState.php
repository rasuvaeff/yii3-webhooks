<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests\Support;

use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;

/**
 * Immutable model of one delivery's claim lifecycle, for the model-based
 * property test in
 * {@see \Rasuvaeff\Yii3Webhooks\Tests\StorageClaimStatefulPropertyTest}.
 *
 * Times are plain second offsets from the start of the sequence, deliberately
 * not `DateTimeImmutable`: the model must decide readiness and lease expiry by
 * arithmetic of its own, or it would be re-asking
 * {@see \Rasuvaeff\Yii3Webhooks\WebhookRetryPolicy} the very question the test
 * exists to cross-check.
 */
final readonly class ClaimState
{
    public function __construct(
        public WebhookDeliveryStatus $status,
        /** The worker holding the lease, or null when nobody does. */
        public ?string $leaseHolder,
        /** When the current lease stops blocking another worker. */
        public int $leaseExpiresAt,
        public int $attempts,
        public ?int $lastAttemptAt,
        public int $now,
    ) {}
}
