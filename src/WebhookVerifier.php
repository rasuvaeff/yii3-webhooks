<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * @api
 */
final readonly class WebhookVerifier
{
    public function __construct(
        private WebhookSigner $signer,
        private ClockInterface $clock,
        private int $toleranceSeconds = 300,
    ) {
        if ($toleranceSeconds < 0) {
            throw new InvalidArgumentException('Tolerance seconds must be non-negative');
        }
    }

    public function verify(
        string $payload,
        #[\SensitiveParameter]
        string $secret,
        WebhookSignature $signature,
        string $eventId,
    ): bool {
        $age = abs($this->clock->now()->getTimestamp() - $signature->getTimestamp());

        if ($age > $this->toleranceSeconds) {
            return false;
        }

        $expected = $this->signer->sign($payload, $secret, $signature->getTimestamp(), $eventId);

        return hash_equals($expected->getValue(), $signature->getValue());
    }
}
