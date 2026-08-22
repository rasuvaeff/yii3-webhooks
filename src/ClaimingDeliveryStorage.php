<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks;

use DateTimeImmutable;

/**
 * A storage that can hand a delivery to exactly one worker.
 *
 * {@see WebhookDeliveryStorage::findPending()} is a plain read: two workers
 * polling the same storage get the same deliveries and both POST them, so the
 * receiver sees one event twice. Nothing in the base contract can prevent that
 * — taking ownership of a row is something only the backend can do atomically.
 *
 * The interface is separate rather than two more methods on
 * {@see WebhookDeliveryStorage} because adding them there would break every
 * third-party storage. A worker picks the path with `instanceof`:
 *
 * ```php
 * if (!$storage instanceof ClaimingDeliveryStorage) {
 *     throw new RuntimeException($storage::class . ' cannot claim; run a single worker instead');
 * }
 *
 * $batch = $storage->claimReady($now, $policy->readyThresholds($now), $policy->getMaxAttempts());
 * ```
 *
 * The `else` branch is a throw and not a call to
 * {@see WebhookDeliveryStorage::findPending()} on purpose: falling back to the
 * plain read is exactly the double delivery this interface exists to prevent,
 * and it fails silently — the receiver sees duplicates, the worker sees nothing.
 *
 * Ownership is a lease, not a status: a claimed delivery stays `Pending` and
 * becomes claimable again once its lease expires, so a worker killed mid-flight
 * never strands a delivery in a state nothing recovers from.
 *
 * @api
 */
interface ClaimingDeliveryStorage extends WebhookDeliveryStorage
{
    /**
     * Atomically leases up to $limit pending deliveries that are ready for
     * another attempt, and returns them.
     *
     * The claim owns the lease and only the lease. It must leave the status
     * alone: a claimed delivery is still `Pending`, and
     * {@see WebhookDeliveryStorage::markDelivered()} /
     * {@see WebhookDeliveryStorage::markFailed()} stay the only methods that
     * ever write one. An implementation that moved the status into a
     * "claimed"/"in-flight" value would take every claimed delivery out of the
     * `Pending` those two compare against, and every outcome after a claim
     * would be dropped as a no-op.
     *
     * A delivery qualifies when its lease is free — never claimed, or claimed
     * longer than $leaseSeconds ago — and any of the following holds:
     *
     * - it has never been attempted (`getLastAttemptAt() === null`);
     * - its last attempt is at or before the threshold for its attempt count;
     * - it has already spent $maxAttempts attempts.
     *
     * The last clause is not an optimisation and must not be dropped. A
     * delivery out of attempts can only ever be marked `Failed`, and the caller
     * can only mark what it was given. Filter it out and nothing terminates it:
     * it stays `Pending` forever, invisible to an alert watching `Failed`.
     *
     * $readyThresholds comes from {@see WebhookRetryPolicy::readyThresholds()}
     * — an implementation must not derive the delays itself, the backoff is the
     * core's business. The threshold of the highest key applies to every larger
     * attempt count as well: the delay stops growing once it reaches the cap.
     *
     * Every returned delivery must be moved on by the caller — with
     * {@see WebhookDeliveryStorage::markDelivered()},
     * {@see WebhookDeliveryStorage::markFailed()}, or {@see self::releaseClaim()}
     * — or it waits out the whole lease before anyone sees it again.
     *
     * @param array<int, DateTimeImmutable> $readyThresholds attempt count => the
     *        latest `lastAttemptAt` that is ready at $now
     * @param int $maxAttempts attempts after which a delivery is exhausted
     * @param int $leaseSeconds how long the claim holds; must outlive the
     *        slowest delivery attempt, or two workers get the same delivery
     * @param int $limit maximum number of deliveries to claim
     *
     * @return list<WebhookDelivery>
     */
    public function claimReady(
        DateTimeImmutable $now,
        array $readyThresholds,
        int $maxAttempts,
        int $leaseSeconds = 300,
        int $limit = 100,
    ): array;

    /**
     * Gives a lease back before it expires, so the delivery is claimable again
     * as soon as its backoff allows instead of after the full lease.
     *
     * Call it for a delivery this worker has claimed and is not terminating.
     * Returns true when a lease was actually cleared — false means the delivery
     * is unknown, no longer pending, or was not leased at all.
     */
    public function releaseClaim(WebhookDelivery $delivery): bool;
}
