<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks;

/**
 * @api
 */
final readonly class HmacSha256Signer implements WebhookSigner
{
    #[\Override]
    public function sign(
        string $payload,
        #[\SensitiveParameter]
        string $secret,
        int $timestamp,
        string $eventId,
    ): WebhookSignature {
        $canonical = $eventId . '.' . $timestamp . '.' . $payload;
        $value = hash_hmac('sha256', $canonical, $secret);

        return new WebhookSignature(
            timestamp: $timestamp,
            value: $value,
        );
    }
}
