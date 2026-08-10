<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests\Support;

use Rasuvaeff\Yii3Webhooks\WebhookDelivery;

/**
 * Mutable holder for the system-under-test side of the model-based property
 * test. The {@see DeliveryCommand::run()} flow reassigns {@see $delivery} on
 * every step, since {@see WebhookDelivery} is immutable. The initial id is
 * captured so the test body can assert identity fields stayed put through
 * the whole sequence.
 */
final class DeliveryHarness
{
    public readonly string $originalId;

    public function __construct(public WebhookDelivery $delivery)
    {
        $this->originalId = $delivery->getId();
    }
}
