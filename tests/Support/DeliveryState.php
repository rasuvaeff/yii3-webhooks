<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests\Support;

use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;

/**
 * Immutable model of the delivery state for the model-based property test in
 * {@see \Rasuvaeff\Yii3Webhooks\Tests\WebhookDeliveryStatefulPropertyTest}.
 *
 * The model tracks only what the test's invariants depend on: the current
 * status, the attempt count, and the last error string. Identity fields
 * (id, eventId, eventType, endpointUrl, createdAt) are immutable by
 * construction in {@see \Rasuvaeff\Yii3Webhooks\WebhookDelivery} and are
 * checked separately in the test body, not threaded through the model.
 */
final readonly class DeliveryState
{
    public function __construct(
        public WebhookDeliveryStatus $status,
        public int $attempts,
        public ?string $lastError,
    ) {}
}
