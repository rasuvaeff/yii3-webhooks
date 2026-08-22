<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks;

/**
 * @api
 */
interface WebhookDeliveryStorage
{
    /**
     * Stores a new delivery, or updates the attempt state of one already stored.
     *
     * The status of a delivery that already exists is *not* written: it belongs
     * to {@see self::markDelivered()}, {@see self::markFailed()} and, where the
     * storage supports one, the claim. An unconditional write would let a stale
     * copy held by a worker that lost the race put a finished delivery back into
     * `Pending` — and deliver the same webhook twice.
     */
    public function save(WebhookDelivery $delivery): void;

    /**
     * The oldest pending deliveries, regardless of whether their backoff has
     * elapsed and regardless of whether another worker is already delivering
     * them.
     *
     * With more than one worker, or with a backlog of deliveries waiting out a
     * backoff, use {@see ClaimingDeliveryStorage::claimReady()} instead.
     *
     * @return list<WebhookDelivery>
     */
    public function findPending(int $limit = 100): array;

    /**
     * Marks the delivery as delivered, if it is still pending.
     *
     * The attempt state (attempts, last attempt, last error) of the passed
     * delivery is persisted along with the status.
     */
    public function markDelivered(WebhookDelivery $delivery): void;

    /**
     * Marks the delivery as failed, if it is still pending.
     *
     * The attempt state (attempts, last attempt, last error) of the passed
     * delivery is persisted along with the status.
     */
    public function markFailed(WebhookDelivery $delivery): void;

    public function getById(string $id): ?WebhookDelivery;
}
