<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use DateTimeImmutable;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Webhooks\InMemoryDeliveryStorage;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;
use Rasuvaeff\Yii3Webhooks\WebhookRetryPolicy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(InMemoryDeliveryStorage::class)]
final class InMemoryDeliveryStorageTest
{
    private InMemoryDeliveryStorage $fixture;
    private WebhookEvent $event;
    private WebhookEndpoint $endpoint;
    private DateTimeImmutable $now;
    private WebhookRetryPolicy $policy;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->fixture = new InMemoryDeliveryStorage();
        $this->event = WebhookEvent::create(type: 'test', payload: '{}');
        $this->endpoint = new WebhookEndpoint(url: 'https://example.com', secret: 'secret');
        $this->now = new DateTimeImmutable('2026-08-22 12:00:00');
        // delays: 30, 60, 120, 240 (capped) — thresholds keyed 1..4
        $this->policy = WebhookRetryPolicy::exponential(maxAttempts: 5, baseSeconds: 30, cap: 240);
    }

    private function delivery(string $id, string $createdAt = '2026-08-22 10:00:00'): WebhookDelivery
    {
        return new WebhookDelivery(
            id: $id,
            eventId: 'evt-1',
            eventType: 'test',
            endpointUrl: 'https://example.com',
            status: WebhookDeliveryStatus::Pending,
            createdAt: new DateTimeImmutable($createdAt),
        );
    }

    public function savesAndRetrievesDelivery(): void
    {
        $delivery = WebhookDelivery::create(event: $this->event, endpoint: $this->endpoint);

        $this->fixture->save($delivery);

        $retrieved = $this->fixture->getById($delivery->getId());

        Assert::notNull($retrieved);
        Assert::same($retrieved->getId(), $delivery->getId());
    }

    public function returnsNullForUnknownId(): void
    {
        Assert::null($this->fixture->getById('nonexistent'));
    }

    public function findPendingReturnsOnlyPending(): void
    {
        $pending = $this->delivery('del-1');
        $delivered = $this->delivery('del-2')->withStatus(WebhookDeliveryStatus::Delivered);

        $this->fixture->save($pending);
        $this->fixture->save($delivered);

        $result = $this->fixture->findPending();

        Assert::count($result, 1);
        Assert::same($result[0]->getId(), 'del-1');
    }

    public function findPendingRespectsLimit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->fixture->save($this->delivery('del-' . $i));
        }

        $result = $this->fixture->findPending(limit: 3);

        Assert::count($result, 3);
    }

    public function markDeliveredUpdatesStatus(): void
    {
        $delivery = $this->delivery('del-1');

        $this->fixture->save($delivery);
        $this->fixture->markDelivered($delivery);

        $retrieved = $this->fixture->getById('del-1');

        Assert::notNull($retrieved);
        Assert::same($retrieved->getStatus(), WebhookDeliveryStatus::Delivered);
    }

    public function markFailedUpdatesStatus(): void
    {
        $delivery = $this->delivery('del-1');

        $this->fixture->save($delivery);
        $this->fixture->markFailed($delivery);

        $retrieved = $this->fixture->getById('del-1');

        Assert::notNull($retrieved);
        Assert::same($retrieved->getStatus(), WebhookDeliveryStatus::Failed);
    }

    /**
     * The terminal transition is a compare-and-set, exactly as a database
     * backend writes it. A divergence here is worse than a plain bug: consumer
     * code debugged against this storage would behave differently in production.
     */
    public function markDeliveredIsANoOpOnAnAlreadyFailedDelivery(): void
    {
        $delivery = $this->delivery('del-1');

        $this->fixture->save($delivery);
        $this->fixture->markFailed($delivery);
        $this->fixture->markDelivered($delivery);

        Assert::same($this->fixture->getById('del-1')?->getStatus(), WebhookDeliveryStatus::Failed);
    }

    public function markFailedIsANoOpOnAnAlreadyDeliveredDelivery(): void
    {
        $delivery = $this->delivery('del-1');

        $this->fixture->save($delivery);
        $this->fixture->markDelivered($delivery);
        $this->fixture->markFailed($delivery);

        Assert::same($this->fixture->getById('del-1')?->getStatus(), WebhookDeliveryStatus::Delivered);
    }

    public function markDeliveredIsANoOpForAnUnknownDelivery(): void
    {
        $this->fixture->markDelivered($this->delivery('nonexistent'));

        Assert::null($this->fixture->getById('nonexistent'));
        Assert::same(count($this->fixture), 0);
    }

    public function markFailedIsANoOpForAnUnknownDelivery(): void
    {
        $this->fixture->markFailed($this->delivery('nonexistent'));

        Assert::null($this->fixture->getById('nonexistent'));
        Assert::same(count($this->fixture), 0);
    }

    public function countReturnsNumberOfDeliveries(): void
    {
        Assert::same($this->fixture->count(), 0);

        $this->fixture->save($this->delivery('del-1'));
        $this->fixture->save($this->delivery('del-2'));

        Assert::same($this->fixture->count(), 2);
    }

    public function clearRemovesAllDeliveries(): void
    {
        $this->fixture->save($this->delivery('del-1'));
        $this->fixture->clear();

        Assert::same($this->fixture->count(), 0);
    }

    public function iteratesOverDeliveries(): void
    {
        $this->fixture->save($this->delivery('del-1'));
        $this->fixture->save($this->delivery('del-2'));

        $ids = [];

        foreach ($this->fixture as $delivery) {
            $ids[] = $delivery->getId();
        }

        Assert::same($ids, ['del-1', 'del-2']);
    }

    public function findPendingReturnsSortedByCreatedAtThenId(): void
    {
        $older = new WebhookDelivery(
            id: 'del-older',
            eventId: 'evt-1',
            eventType: 'test',
            endpointUrl: 'https://example.com',
            status: WebhookDeliveryStatus::Pending,
            createdAt: new \DateTimeImmutable('2026-01-01 10:00:00'),
        );
        $newer = new WebhookDelivery(
            id: 'del-newer',
            eventId: 'evt-1',
            eventType: 'test',
            endpointUrl: 'https://example.com',
            status: WebhookDeliveryStatus::Pending,
            createdAt: new \DateTimeImmutable('2026-01-01 11:00:00'),
        );

        $this->fixture->save($newer);
        $this->fixture->save($older);

        $result = $this->fixture->findPending();

        Assert::same($result[0]->getId(), 'del-older');
        Assert::same($result[1]->getId(), 'del-newer');
    }

    public function findPendingSortsByIdWhenCreatedAtIsEqual(): void
    {
        $sameTime = new \DateTimeImmutable('2026-01-01 10:00:00');
        $first = new WebhookDelivery(
            id: 'del-aaa',
            eventId: 'evt-1',
            eventType: 'test',
            endpointUrl: 'https://example.com',
            status: WebhookDeliveryStatus::Pending,
            createdAt: $sameTime,
        );
        $second = new WebhookDelivery(
            id: 'del-zzz',
            eventId: 'evt-1',
            eventType: 'test',
            endpointUrl: 'https://example.com',
            status: WebhookDeliveryStatus::Pending,
            createdAt: $sameTime,
        );

        $this->fixture->save($second);
        $this->fixture->save($first);

        $result = $this->fixture->findPending();

        Assert::same($result[0]->getId(), 'del-aaa');
        Assert::same($result[1]->getId(), 'del-zzz');
    }

    public function findPendingRespectsDefaultLimitOf100(): void
    {
        for ($i = 1; $i <= 101; $i++) {
            $this->fixture->save($this->delivery('del-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT)));
        }

        Assert::count($this->fixture->findPending(), 100);
    }

    public function countIsAccessibleViaCountable(): void
    {
        $this->fixture->save($this->delivery('del-1'));
        $this->fixture->save($this->delivery('del-2'));

        Assert::same(count($this->fixture), 2);
    }

    // ── save() must not resurrect ────────────────────────────────────────────

    /**
     * The lost-update half of the double-delivery bug: worker A delivered and
     * marked the row, worker B still holds the stale pending copy and records
     * its own failed attempt. An unconditional write would put the finished
     * delivery back into the queue.
     */
    public function saveDoesNotResurrectAFinishedDelivery(): void
    {
        $stale = $this->delivery('del-1');

        $this->fixture->save($stale);
        $this->fixture->markDelivered($stale);
        $this->fixture->save($stale->withAttempt(new DateTimeImmutable(), error: 'HTTP 502'));

        $stored = $this->fixture->getById('del-1');

        Assert::notNull($stored);
        Assert::same($stored->getStatus(), WebhookDeliveryStatus::Delivered);
        // the attempt state is still written — only the status is protected
        Assert::same($stored->getAttempts(), 1);
        Assert::same($stored->getLastError(), 'HTTP 502');
        Assert::count($this->fixture->findPending(), 0);
    }

    public function saveKeepsTheStatusOfANewDelivery(): void
    {
        $this->fixture->save($this->delivery('del-1')->withStatus(WebhookDeliveryStatus::Failed));

        Assert::same($this->fixture->getById('del-1')?->getStatus(), WebhookDeliveryStatus::Failed);
    }

    // ── claimReady() ─────────────────────────────────────────────────────────

    public function claimReadyLeasesEachDeliveryToASingleWorker(): void
    {
        $this->fixture->save($this->delivery('del-1'));
        $this->fixture->save($this->delivery('del-2'));

        $workerA = $this->claim();
        $workerB = $this->claim();

        Assert::same($this->ids($workerA), ['del-1', 'del-2']);
        Assert::same($this->ids($workerB), []);
        // findPending, by contrast, hands both workers the same deliveries
        Assert::count($this->fixture->findPending(), 2);
    }

    /**
     * Head-of-line blocking: a backlog of deliveries waiting out their backoff
     * fills every `findPending()` batch, and the ready ones behind them are
     * never fetched. The claim filters by readiness before applying the limit.
     */
    public function claimReadySkipsBackingOffDeliveriesInsteadOfBlockingBehindThem(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->fixture->save(
                $this->delivery('backing-off-' . $i, createdAt: '2026-08-22 10:00:0' . $i)
                    ->withAttempt(at: $this->now->modify('-1 second'), error: 'HTTP 503'),
            );
        }

        $this->fixture->save($this->delivery('ready', createdAt: '2026-08-22 11:00:00'));

        Assert::same($this->ids($this->fixture->findPending(limit: 5)), [
            'backing-off-1', 'backing-off-2', 'backing-off-3', 'backing-off-4', 'backing-off-5',
        ]);
        Assert::same($this->ids($this->claim(limit: 5)), ['ready']);
    }

    public function claimReadyIncludesADeliveryExactlyAtItsThreshold(): void
    {
        // one attempt => the policy asks for 30 seconds, and the boundary counts
        $this->fixture->save(
            $this->delivery('del-1')->withAttempt(at: $this->now->modify('-30 seconds'), error: 'HTTP 503'),
        );

        Assert::same($this->ids($this->claim()), ['del-1']);
    }

    public function claimReadySkipsADeliveryOneSecondShortOfItsThreshold(): void
    {
        $this->fixture->save(
            $this->delivery('del-1')->withAttempt(at: $this->now->modify('-29 seconds'), error: 'HTTP 503'),
        );

        Assert::same($this->ids($this->claim()), []);
    }

    public function claimReadyReturnsDeliveryWhoseBackoffElapsed(): void
    {
        $this->fixture->save(
            $this->delivery('del-1')->withAttempt(at: $this->now->modify('-61 seconds'), error: 'HTTP 503'),
        );

        Assert::same($this->ids($this->claim()), ['del-1']);
    }

    /**
     * An exhausted delivery must still be handed out: the caller can only mark
     * what it was given, so filtering it here means nothing ever terminates it.
     */
    public function claimReadyReturnsExhaustedDelivery(): void
    {
        $delivery = $this->delivery('del-1');

        for ($i = 1; $i <= 5; $i++) {
            $delivery = $delivery->withAttempt(at: $this->now, error: 'HTTP 503');
        }

        $this->fixture->save($delivery);

        Assert::same($this->ids($this->claim()), ['del-1']);
    }

    public function claimReadySkipsTerminalDeliveries(): void
    {
        $this->fixture->save($this->delivery('del-1'));
        $this->fixture->markFailed($this->delivery('del-1'));

        Assert::same($this->ids($this->claim()), []);
    }

    public function claimReadyRespectsLimit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->fixture->save($this->delivery('del-' . $i, createdAt: '2026-08-22 10:0' . $i . ':00'));
        }

        Assert::count($this->claim(limit: 2), 2);
    }

    public function claimReadyHandsOutADeliveryAgainOnceTheLeaseExpires(): void
    {
        $this->fixture->save($this->delivery('del-1'));

        Assert::same($this->ids($this->claim()), ['del-1']);
        Assert::same($this->ids($this->claim(now: $this->now->modify('+299 seconds'))), []);
        Assert::same($this->ids($this->claim(now: $this->now->modify('+300 seconds'))), ['del-1']);
    }

    // ── releaseClaim() ───────────────────────────────────────────────────────

    public function releaseClaimMakesTheDeliveryClaimableAgain(): void
    {
        $this->fixture->save($this->delivery('del-1'));
        $claimed = $this->claim();

        Assert::true($this->fixture->releaseClaim($claimed[0]));
        Assert::same($this->ids($this->claim()), ['del-1']);
    }

    public function releaseClaimReturnsFalseWhenNothingWasLeased(): void
    {
        $this->fixture->save($this->delivery('del-1'));

        Assert::false($this->fixture->releaseClaim($this->delivery('del-1')));
    }

    public function releaseClaimReturnsFalseForAnUnknownDelivery(): void
    {
        Assert::false($this->fixture->releaseClaim($this->delivery('nonexistent')));
    }

    public function releaseClaimReturnsFalseForATerminalDelivery(): void
    {
        $this->fixture->save($this->delivery('del-1'));
        $claimed = $this->claim();
        $this->fixture->markDelivered($claimed[0]);

        Assert::false($this->fixture->releaseClaim($claimed[0]));
    }

    public function clearDropsLeasesToo(): void
    {
        $this->fixture->save($this->delivery('del-1'));
        $this->claim();
        $this->fixture->clear();
        $this->fixture->save($this->delivery('del-1'));

        Assert::same($this->ids($this->claim()), ['del-1']);
    }

    /**
     * Two workers polling the same storage never receive the same delivery, and
     * every claimed delivery is one the per-delivery policy check accepts —
     * the claim is the same predicate, applied before the limit.
     */
    #[Property(runs: 200, timeoutMs: 2_000)]
    public function claimIsExclusiveAndAgreesWithThePolicy(array $specs): void
    {
        $storage = new InMemoryDeliveryStorage();
        $policy = $this->policy;

        foreach ($specs as $index => $spec) {
            \assert(\is_array($spec) && \is_int($spec['attempts']) && \is_int($spec['agoSeconds']));

            $delivery = $this->delivery('del-' . $index);

            for ($i = 0; $i < $spec['attempts']; $i++) {
                $delivery = $delivery->withAttempt(
                    at: $this->now->modify('-' . $spec['agoSeconds'] . ' seconds'),
                    error: 'HTTP 503',
                );
            }

            $storage->save($delivery);
        }

        $thresholds = $policy->readyThresholds($this->now);
        $workerA = $storage->claimReady(
            now: $this->now,
            readyThresholds: $thresholds,
            maxAttempts: $policy->getMaxAttempts(),
        );
        $workerB = $storage->claimReady(
            now: $this->now,
            readyThresholds: $thresholds,
            maxAttempts: $policy->getMaxAttempts(),
        );

        Assert::same(array_values(array_intersect($this->ids($workerA), $this->ids($workerB))), []);

        $exhausted = false;

        foreach ($workerA as $delivery) {
            $isExhausted = $delivery->getAttempts() >= $policy->getMaxAttempts();
            $exhausted = $exhausted || $isExhausted;

            Assert::true($isExhausted || $policy->isReadyForRetry($delivery, $this->now));
        }

        Classify::cover(condition: $workerA !== [], label: 'claimed something', minPercent: 30.0);
        Classify::cover(
            condition: count($workerA) < count($specs),
            label: 'at least one delivery skipped',
            minPercent: 10.0,
        );
        Classify::when(condition: $exhausted, label: 'exhausted delivery claimed');
    }

    /** @return array<string, ArbitraryInterface> */
    public static function claimIsExclusiveAndAgreesWithThePolicyGenerators(): array
    {
        return [
            'specs' => Gen::arrayOf(
                Gen::record([
                    'attempts' => Gen::intBetween(0, 6),
                    'agoSeconds' => Gen::intBetween(0, 600),
                ]),
                1,
                8,
            ),
        ];
    }

    /** @return iterable<string, array{list<array{attempts: int, agoSeconds: int}>}> */
    public static function claimIsExclusiveAndAgreesWithThePolicyExamples(): iterable
    {
        yield 'fresh delivery only' => [[['attempts' => 0, 'agoSeconds' => 0]]];
        yield 'exactly at the first threshold' => [[['attempts' => 1, 'agoSeconds' => 30]]];
        yield 'one second short of the first threshold' => [[['attempts' => 1, 'agoSeconds' => 29]]];
        yield 'exhausted' => [[['attempts' => 5, 'agoSeconds' => 0]]];
    }

    /**
     * @param list<WebhookDelivery> $deliveries
     *
     * @return list<string>
     */
    private function ids(array $deliveries): array
    {
        return array_map(static fn(WebhookDelivery $delivery): string => $delivery->getId(), $deliveries);
    }

    /** @return list<WebhookDelivery> */
    private function claim(?DateTimeImmutable $now = null, int $limit = 100): array
    {
        $at = $now ?? $this->now;

        return $this->fixture->claimReady(
            now: $at,
            readyThresholds: $this->policy->readyThresholds($at),
            maxAttempts: $this->policy->getMaxAttempts(),
            limit: $limit,
        );
    }
}
