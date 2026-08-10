<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests\Support;

use DateTimeImmutable;
use Rasuvaeff\PropertyTesting\StateMachine\Command;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;

/**
 * One lifecycle step for {@see WebhookDelivery}, doubling as a model-based
 * test command. Mirrors the public API surface a dispatcher would call:
 * recording an attempt ({@see WebhookDelivery::withAttempt()}), then marking
 * success ({@see WebhookDelivery::withStatus()} with
 * {@see WebhookDeliveryStatus::Delivered}) or giving up
 * ({@see WebhookDeliveryStatus::Failed}).
 *
 * The model is a {@see DeliveryState}; the system under test is a
 * {@see WebhookDelivery} (immutable, so each {@see run()} reassigns the
 * harness's current delivery).
 */
final readonly class DeliveryCommand implements Command
{
    public function __construct(
        private DeliveryAction $action,
        private ?string $error = null,
    ) {}

    #[\Override]
    public function preCondition(mixed $model): bool
    {
        // WebhookDelivery's withStatus/withAttempt do not forbid any transition
        // (the package is a state holder, not a state machine — retry policy
        // lives elsewhere). All three actions apply from every state.
        return true;
    }

    #[\Override]
    public function nextState(mixed $model): DeliveryState
    {
        \assert($model instanceof DeliveryState);

        return match ($this->action) {
            DeliveryAction::Attempt => new DeliveryState(
                status: $model->status,
                attempts: $model->attempts + 1,
                lastError: $this->error,
            ),
            DeliveryAction::Succeed => new DeliveryState(
                status: WebhookDeliveryStatus::Delivered,
                attempts: $model->attempts,
                lastError: $model->lastError,
            ),
            DeliveryAction::Fail => new DeliveryState(
                status: WebhookDeliveryStatus::Failed,
                attempts: $model->attempts,
                lastError: $model->lastError,
            ),
        };
    }

    #[\Override]
    public function run(mixed $model, mixed $system): WebhookDelivery
    {
        \assert($system instanceof DeliveryHarness);

        $current = $system->delivery;
        $clock = self::frozenClock();

        $next = match ($this->action) {
            DeliveryAction::Attempt => $current->withAttempt(at: $clock, error: $this->error),
            DeliveryAction::Succeed => $current->withStatus(status: WebhookDeliveryStatus::Delivered),
            DeliveryAction::Fail => $current->withStatus(status: WebhookDeliveryStatus::Failed),
        };

        return $system->delivery = $next;
    }

    #[\Override]
    public function postCondition(mixed $model, mixed $result): bool
    {
        \assert($model instanceof DeliveryState);
        \assert($result instanceof WebhookDelivery);

        $expected = $this->nextState(model: $model);

        return $result->getStatus() === $expected->status
            && $result->getAttempts() === $expected->attempts
            && $result->getLastError() === $expected->lastError;
    }

    #[\Override]
    public function __toString(): string
    {
        return match ($this->action) {
            DeliveryAction::Attempt => "attempt(error=" . ($this->error ?? 'null') . ')',
            DeliveryAction::Succeed => 'succeed',
            DeliveryAction::Fail => 'fail',
        };
    }

    private static function frozenClock(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01 00:00:00');
    }
}
