<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks;

use ArrayIterator;
use Countable;
use DateTimeImmutable;
use IteratorAggregate;
use Traversable;

/**
 * @api
 *
 * @implements IteratorAggregate<string, WebhookDelivery>
 */
final class InMemoryDeliveryStorage implements ClaimingDeliveryStorage, IteratorAggregate, Countable
{
    /** @var array<string, WebhookDelivery> */
    private array $deliveries = [];

    /**
     * Leases, by delivery id — the in-memory equivalent of the `claimed_at`
     * column a database backend keeps.
     *
     * @var array<string, DateTimeImmutable>
     */
    private array $claims = [];

    #[\Override]
    public function save(WebhookDelivery $delivery): void
    {
        $existing = $this->deliveries[$delivery->getId()] ?? null;

        // the status of a delivery already stored belongs to mark*/claim, not
        // to whoever holds a copy of it: an unconditional write lets a worker
        // that lost the race resurrect a finished delivery
        $this->deliveries[$delivery->getId()] = $existing instanceof WebhookDelivery
            ? $delivery->withStatus($existing->getStatus())
            : $delivery;
    }

    #[\Override]
    public function findPending(int $limit = 100): array
    {
        $pending = array_filter(
            $this->deliveries,
            static fn(WebhookDelivery $d): bool => $d->getStatus() === WebhookDeliveryStatus::Pending,
        );

        usort($pending, self::compareQueueOrder(...));

        return array_slice($pending, 0, $limit);
    }

    #[\Override]
    public function claimReady(
        DateTimeImmutable $now,
        array $readyThresholds,
        int $maxAttempts,
        int $leaseSeconds = 300,
        int $limit = 100,
    ): array {
        $leaseExpiry = $now->modify('-' . $leaseSeconds . ' seconds');

        $claimable = array_filter(
            $this->deliveries,
            fn(WebhookDelivery $d): bool => $d->getStatus() === WebhookDeliveryStatus::Pending
                && $this->isLeaseFree(id: $d->getId(), leaseExpiry: $leaseExpiry)
                && self::isReady(delivery: $d, readyThresholds: $readyThresholds, maxAttempts: $maxAttempts),
        );

        usort($claimable, self::compareQueueOrder(...));

        $claimed = array_slice($claimable, 0, $limit);

        foreach ($claimed as $delivery) {
            $this->claims[$delivery->getId()] = $now;
        }

        return $claimed;
    }

    #[\Override]
    public function releaseClaim(WebhookDelivery $delivery): bool
    {
        $id = $delivery->getId();
        $stored = $this->deliveries[$id] ?? null;

        if (!$stored instanceof WebhookDelivery
            || $stored->getStatus() !== WebhookDeliveryStatus::Pending
            || !isset($this->claims[$id])
        ) {
            return false;
        }

        unset($this->claims[$id]);

        return true;
    }

    #[\Override]
    public function markDelivered(WebhookDelivery $delivery): void
    {
        $this->deliveries[$delivery->getId()] = $delivery->withStatus(WebhookDeliveryStatus::Delivered);
        unset($this->claims[$delivery->getId()]);
    }

    #[\Override]
    public function markFailed(WebhookDelivery $delivery): void
    {
        $this->deliveries[$delivery->getId()] = $delivery->withStatus(WebhookDeliveryStatus::Failed);
        unset($this->claims[$delivery->getId()]);
    }

    #[\Override]
    public function getById(string $id): ?WebhookDelivery
    {
        return $this->deliveries[$id] ?? null;
    }

    #[\Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->deliveries);
    }

    #[\Override]
    public function count(): int
    {
        return count($this->deliveries);
    }

    public function clear(): void
    {
        $this->deliveries = [];
        $this->claims = [];
    }

    private function isLeaseFree(string $id, DateTimeImmutable $leaseExpiry): bool
    {
        $claimedAt = $this->claims[$id] ?? null;

        return !$claimedAt instanceof DateTimeImmutable || $claimedAt <= $leaseExpiry;
    }

    /**
     * The same predicate a database backend expresses as SQL — see
     * {@see ClaimingDeliveryStorage::claimReady()} for why an exhausted
     * delivery counts as ready.
     *
     * @param array<int, DateTimeImmutable> $readyThresholds ascending by key
     */
    private static function isReady(WebhookDelivery $delivery, array $readyThresholds, int $maxAttempts): bool
    {
        $attempts = $delivery->getAttempts();

        if ($attempts >= $maxAttempts || $attempts < 1) {
            return true;
        }

        $lastAttemptAt = $delivery->getLastAttemptAt();

        if (!$lastAttemptAt instanceof DateTimeImmutable) {
            return true;
        }

        $applicable = null;

        // the highest key at or below this attempt count wins: the map ends
        // where the delay stopped growing
        foreach ($readyThresholds as $count => $threshold) {
            if ($count <= $attempts) {
                $applicable = $threshold;
            }
        }

        return !$applicable instanceof DateTimeImmutable || $lastAttemptAt <= $applicable;
    }

    private static function compareQueueOrder(WebhookDelivery $a, WebhookDelivery $b): int
    {
        $cmp = $a->getCreatedAt() <=> $b->getCreatedAt();

        return $cmp !== 0 ? $cmp : ($a->getId() <=> $b->getId());
    }
}
