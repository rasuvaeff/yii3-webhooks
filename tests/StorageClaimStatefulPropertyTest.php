<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use DateTimeImmutable;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\PropertyTesting\StateMachine\CommandSequence;
use Rasuvaeff\PropertyTesting\StateMachine\StateMachine;
use Rasuvaeff\Yii3Webhooks\InMemoryDeliveryStorage;
use Rasuvaeff\Yii3Webhooks\Tests\Support\ClaimAction;
use Rasuvaeff\Yii3Webhooks\Tests\Support\ClaimCommand;
use Rasuvaeff\Yii3Webhooks\Tests\Support\ClaimHarness;
use Rasuvaeff\Yii3Webhooks\Tests\Support\ClaimState;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Model-based property test for the claim lifecycle of
 * {@see InMemoryDeliveryStorage}: two workers racing for the same delivery,
 * interleaved with attempts, terminations and the passage of time.
 *
 * The invariants a claim exists for are all encoded in the model and checked on
 * every step:
 *
 * - **exclusivity** — while one worker holds a live lease, the other is refused;
 * - **the lease expires** — a worker that dies strands nothing, the delivery
 *   becomes claimable again after `leaseSeconds`;
 * - **backoff** — a delivery whose last attempt is inside the delay is not
 *   handed out, and one that is out of attempts always is (nothing else could
 *   ever terminate it);
 * - **terminal is terminal** — a finished delivery is never claimed again, and
 *   neither `save()` nor a losing `mark*()` puts it back into the queue.
 */
#[Test]
#[Covers(InMemoryDeliveryStorage::class)]
final class StorageClaimStatefulPropertyTest
{
    private const string BASE_TIME = '2026-08-22 12:00:00';

    /** @return list<ArbitraryInterface> */
    private static function commandGenerators(): array
    {
        return [
            // two workers polling the same storage — the whole point of a claim
            Gen::constant(value: new ClaimCommand(action: ClaimAction::Claim, worker: 'A')),
            Gen::constant(value: new ClaimCommand(action: ClaimAction::Claim, worker: 'B')),
            Gen::constant(value: new ClaimCommand(action: ClaimAction::Release, worker: 'A')),
            Gen::constant(value: new ClaimCommand(action: ClaimAction::Release, worker: 'B')),
            // a failed POST: the attempt is recorded and the backoff starts
            Gen::constant(value: new ClaimCommand(action: ClaimAction::Attempt)),
            Gen::constant(value: new ClaimCommand(action: ClaimAction::Succeed)),
            Gen::constant(value: new ClaimCommand(action: ClaimAction::Fail)),
            // shorter than both the backoff and the lease: nothing frees up
            Gen::constant(value: new ClaimCommand(action: ClaimAction::Wait, seconds: 30)),
            // longer than the backoff, shorter than the lease
            Gen::constant(value: new ClaimCommand(action: ClaimAction::Wait, seconds: 90)),
            // longer than the lease: a dead worker's delivery comes back
            Gen::constant(value: new ClaimCommand(action: ClaimAction::Wait, seconds: 301)),
        ];
    }

    #[Property(runs: 150)]
    public function claimLifecycleTracksTheModel(CommandSequence $sequence): void
    {
        $harness = null;

        StateMachine::check(
            $sequence,
            static function () use (&$harness): ClaimHarness {
                $storage = new InMemoryDeliveryStorage();
                $delivery = WebhookDelivery::create(
                    event: WebhookEvent::create(type: 'order.created', payload: '{"orderId":1}'),
                    endpoint: new WebhookEndpoint(url: 'https://example.com/hook', secret: 'secret'),
                    createdAt: new DateTimeImmutable(self::BASE_TIME),
                );
                $storage->save($delivery);

                return $harness = new ClaimHarness(
                    storage: $storage,
                    delivery: $delivery,
                    now: new DateTimeImmutable(self::BASE_TIME),
                );
            },
        );

        \assert($harness instanceof ClaimHarness);

        $stored = $harness->storage->getById($harness->delivery->getId());

        Assert::notNull($stored);
        Assert::same($stored->getId(), $harness->delivery->getId());
        // a claim is a lease, not a row: no sequence duplicates the delivery
        Assert::same(count($harness->storage), 1);

        if ($stored->getStatus() !== WebhookDeliveryStatus::Pending) {
            // whatever the sequence did, nothing resurrects a finished delivery
            Assert::same($harness->storage->findPending(), []);
        }

        Classify::when(condition: $stored->getStatus() === WebhookDeliveryStatus::Delivered, label: 'terminal:delivered');
        Classify::when(condition: $stored->getStatus() === WebhookDeliveryStatus::Failed, label: 'terminal:failed');
        Classify::when(condition: $stored->getStatus() === WebhookDeliveryStatus::Pending, label: 'terminal:none');

        // Both branches of the claim must be reached across the batch. A
        // regression that hands the delivery to everyone, or to nobody, makes
        // one of these floors miss even though every postcondition still holds
        // for the sequences that happen to avoid the branch.
        Classify::cover(condition: $harness->claimsGranted > 0, label: 'a lease was granted', minPercent: 20.0);
        Classify::cover(condition: $harness->claimsRefused > 0, label: 'a claim was refused', minPercent: 20.0);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function claimLifecycleTracksTheModelGenerators(): array
    {
        return [
            'sequence' => Gen::commands(
                new ClaimState(
                    status: WebhookDeliveryStatus::Pending,
                    leaseHolder: null,
                    leaseExpiresAt: 0,
                    attempts: 0,
                    lastAttemptAt: null,
                    now: 0,
                ),
                self::commandGenerators(),
                minLength: 1,
                maxLength: 24,
            ),
        ];
    }
}
