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
     * The status of a delivery that already exists is *not* written: it is
     * changed by {@see self::markDelivered()} and {@see self::markFailed()},
     * and by nothing else. An unconditional write would let a stale copy held by
     * a worker that lost the race put a finished delivery back into `Pending` —
     * and deliver the same webhook twice.
     *
     * {@see ClaimingDeliveryStorage::claimReady()} is not an exception to that
     * rule and an implementation must not make it one: a claim writes a lease,
     * the delivery it hands out stays `Pending`, and the two terminal methods
     * above remain the only writers of the status. A backend that flipped the
     * status while claiming would put every claimed delivery outside the
     * `WHERE status = pending` those methods compare against, and their
     * compare-and-set would silently stop recording outcomes.
     */
    public function save(WebhookDelivery $delivery): void;

    /**
     * The oldest pending deliveries, regardless of whether their backoff has
     * elapsed and regardless of whether another worker is already delivering
     * them.
     *
     * With more than one worker, or with a backlog of deliveries waiting out a
     * backoff, use {@see ClaimingDeliveryStorage::claimReady()} instead — and
     * not as a fallback when the storage does not implement it. Every worker
     * polling this method gets the same batch and POSTs all of it, so a
     * `instanceof ... ? claimReady() : findPending()` loop degrades into
     * delivering each webhook once per worker. A storage that cannot claim
     * means one worker, not a lesser one.
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
