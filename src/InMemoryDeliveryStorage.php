<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @api
 *
 * @implements IteratorAggregate<string, WebhookDelivery>
 */
final class InMemoryDeliveryStorage implements WebhookDeliveryStorage, IteratorAggregate, Countable
{
    /** @var array<string, WebhookDelivery> */
    private array $deliveries = [];

    #[\Override]
    public function save(WebhookDelivery $delivery): void
    {
        $this->deliveries[$delivery->getId()] = $delivery;
    }

    #[\Override]
    public function findPending(int $limit = 100): array
    {
        $pending = array_filter(
            $this->deliveries,
            static fn(WebhookDelivery $d): bool => $d->getStatus() === WebhookDeliveryStatus::Pending,
        );

        usort($pending, static function (WebhookDelivery $a, WebhookDelivery $b): int {
            $cmp = $a->getCreatedAt() <=> $b->getCreatedAt();

            return $cmp !== 0 ? $cmp : ($a->getId() <=> $b->getId());
        });

        return array_slice($pending, 0, $limit);
    }

    #[\Override]
    public function markDelivered(WebhookDelivery $delivery): void
    {
        $this->deliveries[$delivery->getId()] = $delivery->withStatus(WebhookDeliveryStatus::Delivered);
    }

    #[\Override]
    public function markFailed(WebhookDelivery $delivery): void
    {
        $this->deliveries[$delivery->getId()] = $delivery->withStatus(WebhookDeliveryStatus::Failed);
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
    }
}
