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

        // the cap is applied in float space, before the cast: an exponential
        // policy with a large maxAttempts overflows PHP_INT_MAX within a few
        // dozen attempts, and casting an out-of-range float is platform-defined
        // (typically PHP_INT_MIN) — min() would then pick the negative value
        // and isReadyForRetry() would build "+-9223372036854775808 seconds"
        return (int) round(min($delay, (float) $this->capSeconds));
    }

    /**
     * The `lastAttemptAt` boundary at which a delivery becomes ready again, per
     * attempt count — the same inequality {@see self::isReadyForRetry()} asks
     * one delivery at a time (`lastAttemptAt + delay <= now`), rearranged into
     * data a storage backend can push into its own query.
     *
     * The threshold of the highest key applies to every larger attempt count as
     * well: the delay stops growing once it reaches the cap, so the map ends
     * there instead of repeating one value up to `maxAttempts`.
     *
     * @return array<int, DateTimeImmutable> attempt count => latest
     *         `lastAttemptAt` that is ready at $now
     *
     * @see ClaimingDeliveryStorage::claimReady()
     */
    public function readyThresholds(DateTimeImmutable $now): array
    {
        $thresholds = [];
        $previousDelay = null;

        for ($attempts = 1; $attempts < $this->maxAttempts; $attempts++) {
            $delay = $this->nextDelaySeconds(attempts: $attempts);
            $thresholds[$attempts] = $now->modify('-' . $delay . ' seconds');

            // the delay has stopped growing — either it reached the cap or the
            // policy has none to grow by — so this threshold already covers
            // every larger attempt count, and a maxAttempts of a few hundred
            // must not turn into a few hundred clauses in a backend's query
            if ($delay >= $this->capSeconds || $delay === $previousDelay) {
                break;
            }

            $previousDelay = $delay;
        }

        return $thresholds;
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
