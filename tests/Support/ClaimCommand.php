<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests\Support;

use Rasuvaeff\PropertyTesting\StateMachine\Command;
use Rasuvaeff\Yii3Webhooks\ClaimingDeliveryStorage;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookRetryPolicy;

/**
 * One step of the claim lifecycle, doubling as a model-based test command.
 *
 * The model ({@see ClaimState}) decides by arithmetic whether a claim is due;
 * the system under test ({@see ClaimHarness}) asks
 * {@see WebhookRetryPolicy::readyThresholds()} and
 * {@see ClaimingDeliveryStorage::claimReady()}. The postcondition compares the
 * two, so the test cross-checks the storage against the policy rather than
 * against itself.
 */
final readonly class ClaimCommand implements Command
{
    public const int LEASE_SECONDS = 300;
    public const int MAX_ATTEMPTS = 3;
    public const int DELAY_SECONDS = 60;

    public function __construct(
        private ClaimAction $action,
        private string $worker = 'A',
        private int $seconds = 0,
    ) {}

    #[\Override]
    public function preCondition(mixed $model): bool
    {
        // every step is legal from every state — a worker calling claimReady()
        // on a delivery somebody else finished is exactly the race under test
        return true;
    }

    #[\Override]
    public function nextState(mixed $model): ClaimState
    {
        \assert($model instanceof ClaimState);

        $applies = $this->applies($model);

        return match ($this->action) {
            ClaimAction::Claim => $applies
                ? new ClaimState(
                    status: $model->status,
                    leaseHolder: $this->worker,
                    leaseExpiresAt: $model->now + self::LEASE_SECONDS,
                    attempts: $model->attempts,
                    lastAttemptAt: $model->lastAttemptAt,
                    now: $model->now,
                )
                : $model,
            ClaimAction::Release => $applies
                ? new ClaimState(
                    status: $model->status,
                    leaseHolder: null,
                    leaseExpiresAt: $model->leaseExpiresAt,
                    attempts: $model->attempts,
                    lastAttemptAt: $model->lastAttemptAt,
                    now: $model->now,
                )
                : $model,
            // save() writes the attempt state of an existing delivery but never
            // its status, so a terminal delivery still counts the attempt
            ClaimAction::Attempt => new ClaimState(
                status: $model->status,
                leaseHolder: $model->leaseHolder,
                leaseExpiresAt: $model->leaseExpiresAt,
                attempts: $model->attempts + 1,
                lastAttemptAt: $model->now,
                now: $model->now,
            ),
            ClaimAction::Succeed, ClaimAction::Fail => $model->status === WebhookDeliveryStatus::Pending
                ? new ClaimState(
                    status: $this->action === ClaimAction::Succeed
                        ? WebhookDeliveryStatus::Delivered
                        : WebhookDeliveryStatus::Failed,
                    leaseHolder: null,
                    leaseExpiresAt: $model->leaseExpiresAt,
                    attempts: $model->attempts,
                    lastAttemptAt: $model->lastAttemptAt,
                    now: $model->now,
                )
                : $model,
            ClaimAction::Wait => new ClaimState(
                status: $model->status,
                leaseHolder: $model->leaseHolder,
                leaseExpiresAt: $model->leaseExpiresAt,
                attempts: $model->attempts,
                lastAttemptAt: $model->lastAttemptAt,
                now: $model->now + $this->seconds,
            ),
        };
    }

    /**
     * @return array{applied: bool, status: WebhookDeliveryStatus, attempts: int}
     */
    #[\Override]
    public function run(mixed $model, mixed $system): array
    {
        \assert($system instanceof ClaimHarness);

        $applied = match ($this->action) {
            ClaimAction::Claim => $this->claim($system),
            ClaimAction::Release => $system->storage->releaseClaim($system->delivery),
            ClaimAction::Attempt => $this->attempt($system),
            ClaimAction::Succeed => $this->terminate($system, WebhookDeliveryStatus::Delivered),
            ClaimAction::Fail => $this->terminate($system, WebhookDeliveryStatus::Failed),
            ClaimAction::Wait => $this->wait($system),
        };

        $stored = $system->storage->getById($system->delivery->getId());

        \assert($stored instanceof WebhookDelivery);

        return ['applied' => $applied, 'status' => $stored->getStatus(), 'attempts' => $stored->getAttempts()];
    }

    #[\Override]
    public function postCondition(mixed $model, mixed $result): bool
    {
        \assert($model instanceof ClaimState);
        \assert(\is_array($result));

        $expected = $this->nextState($model);

        return $result['applied'] === $this->applies($model)
            && $result['status'] === $expected->status
            && $result['attempts'] === $expected->attempts;
    }

    #[\Override]
    public function __toString(): string
    {
        return match ($this->action) {
            ClaimAction::Claim => 'claim(' . $this->worker . ')',
            ClaimAction::Release => 'release(' . $this->worker . ')',
            ClaimAction::Attempt => 'attempt',
            ClaimAction::Succeed => 'succeed',
            ClaimAction::Fail => 'fail',
            ClaimAction::Wait => 'wait(' . $this->seconds . 's)',
        };
    }

    /**
     * Whether the step changes anything — and, for claim and release, the
     * boolean the storage is expected to report.
     */
    private function applies(ClaimState $model): bool
    {
        return match ($this->action) {
            ClaimAction::Claim => $model->status === WebhookDeliveryStatus::Pending
                && ($model->leaseHolder === null || $model->now >= $model->leaseExpiresAt)
                && $this->isReady($model),
            ClaimAction::Release => $model->status === WebhookDeliveryStatus::Pending
                && $model->leaseHolder !== null,
            default => true,
        };
    }

    /**
     * The readiness rule as the model sees it: never attempted, out of
     * attempts, or the fixed delay has elapsed since the last attempt.
     */
    private function isReady(ClaimState $model): bool
    {
        return $model->attempts >= self::MAX_ATTEMPTS
            || $model->attempts < 1
            || $model->lastAttemptAt === null
            || $model->lastAttemptAt <= $model->now - self::DELAY_SECONDS;
    }

    private function claim(ClaimHarness $system): bool
    {
        $policy = WebhookRetryPolicy::fixed(maxAttempts: self::MAX_ATTEMPTS, delaySeconds: self::DELAY_SECONDS);

        $claimed = $system->storage->claimReady(
            now: $system->now,
            readyThresholds: $policy->readyThresholds($system->now),
            maxAttempts: $policy->getMaxAttempts(),
            leaseSeconds: self::LEASE_SECONDS,
        );

        $granted = $claimed !== [];

        if ($granted) {
            $system->claimsGranted++;
        } else {
            $system->claimsRefused++;
        }

        return $granted;
    }

    private function attempt(ClaimHarness $system): bool
    {
        $system->delivery = $system->delivery->withAttempt(at: $system->now, error: 'HTTP 503');
        $system->storage->save($system->delivery);

        return true;
    }

    private function terminate(ClaimHarness $system, WebhookDeliveryStatus $status): bool
    {
        if ($status === WebhookDeliveryStatus::Delivered) {
            $system->storage->markDelivered($system->delivery);
        } else {
            $system->storage->markFailed($system->delivery);
        }

        return true;
    }

    private function wait(ClaimHarness $system): bool
    {
        $system->now = $system->now->modify('+' . $this->seconds . ' seconds');

        return true;
    }
}
