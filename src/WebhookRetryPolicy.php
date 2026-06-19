<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * @api
 */
final readonly class WebhookRetryPolicy
{
    private function __construct(
        private int $maxAttempts,
        private int $baseDelaySeconds,
        private float $multiplier,
        private int $capSeconds,
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('Max attempts must be at least 1');
        }

        if ($baseDelaySeconds < 0) {
            throw new InvalidArgumentException('Base delay seconds must be non-negative');
        }

        if ($multiplier < 1.0) {
            throw new InvalidArgumentException('Multiplier must be at least 1.0');
        }

        if ($capSeconds < $baseDelaySeconds) {
            throw new InvalidArgumentException('Cap seconds must be >= base delay seconds');
        }
    }

    public static function fixed(int $maxAttempts = 3, int $delaySeconds = 60): self
    {
        return new self(
            maxAttempts: $maxAttempts,
            baseDelaySeconds: $delaySeconds,
            multiplier: 1.0,
            capSeconds: $delaySeconds,
        );
    }

    public static function exponential(
        int $maxAttempts = 5,
        int $baseSeconds = 10,
        int $cap = 3600,
        float $multiplier = 2.0,
    ): self {
        return new self(
            maxAttempts: $maxAttempts,
            baseDelaySeconds: $baseSeconds,
            multiplier: $multiplier,
            capSeconds: $cap,
        );
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * Returns the delay in seconds before the next attempt.
     *
     * @param int $attempts current attempt count on the delivery (>= 1)
     */
    public function nextDelaySeconds(int $attempts): int
    {
        $delay = (float) $this->baseDelaySeconds * ($this->multiplier ** (float) ($attempts - 1));

        return min((int) round($delay), $this->capSeconds);
    }

    public function shouldRetry(WebhookDelivery $delivery): bool
    {
        return $delivery->getStatus() === WebhookDeliveryStatus::Pending
            && $delivery->getAttempts() < $this->maxAttempts;
    }

    public function isReadyForRetry(WebhookDelivery $delivery, DateTimeImmutable $now): bool
    {
        if (!$this->shouldRetry($delivery)) {
            return false;
        }

        $lastAttempt = $delivery->getLastAttemptAt();

        if (!$lastAttempt instanceof DateTimeImmutable) {
            return true;
        }

        $delay = $this->nextDelaySeconds($delivery->getAttempts());
        $nextAttemptAt = $lastAttempt->modify('+' . $delay . ' seconds');

        return $now >= $nextAttemptAt;
    }
}
