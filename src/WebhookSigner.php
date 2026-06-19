<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks;

/**
 * @api
 */
interface WebhookSigner
{
    public function sign(string $payload, string $secret, int $timestamp, string $eventId): WebhookSignature;
}
