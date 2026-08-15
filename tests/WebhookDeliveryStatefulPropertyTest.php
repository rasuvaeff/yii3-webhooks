<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\PropertyTesting\StateMachine\CommandSequence;
use Rasuvaeff\PropertyTesting\StateMachine\StateMachine;
use Rasuvaeff\Yii3Webhooks\Tests\Support\DeliveryAction;
use Rasuvaeff\Yii3Webhooks\Tests\Support\DeliveryCommand;
use Rasuvaeff\Yii3Webhooks\Tests\Support\DeliveryHarness;
use Rasuvaeff\Yii3Webhooks\Tests\Support\DeliveryState;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Model-based property test for {@see WebhookDelivery}'s lifecycle: any
 * sequence of attempt/succeed/fail commands must keep the real delivery's
 * observable state (status, attempts, lastError) in lock-step with the
 * pure {@see DeliveryState} model. Coverage requirements pin that each
 * terminal status is actually reached across a batch of random sequences.
 */
#[Test]
#[Covers(WebhookDelivery::class)]
final class WebhookDeliveryStatefulPropertyTest
{
    /** @return list<ArbitraryInterface> */
    private static function commandGenerators(): array
    {
        return [
            // Record an attempt with no error (transient network blip cleared on retry)
            Gen::constant(value: new DeliveryCommand(action: DeliveryAction::Attempt)),
            // Record an attempt with a typed error (realistic dispatcher payload)
            Gen::constant(value: new DeliveryCommand(action: DeliveryAction::Attempt, error: 'HTTP 503')),
            // Mark success — terminal
            Gen::constant(value: new DeliveryCommand(action: DeliveryAction::Succeed)),
            // Mark failure — terminal
            Gen::constant(value: new DeliveryCommand(action: DeliveryAction::Fail)),
        ];
    }

    #[Property(runs: 100)]
    public function lifecycleTracksModelAndKeepsIdentityImmutable(CommandSequence $sequence): void
    {
        $event = WebhookEvent::create(type: 'order.created', payload: '{"orderId":1}');
        $endpoint = new WebhookEndpoint(url: 'https://example.com', secret: 'secret');

        $harness = null;

        StateMachine::check(
            $sequence,
            static function () use ($event, $endpoint, &$harness): DeliveryHarness {
                $delivery = WebhookDelivery::create(event: $event, endpoint: $endpoint);

                return $harness = new DeliveryHarness(delivery: $delivery);
            },
        );

        \assert($harness instanceof DeliveryHarness);

        // Identity fields must not move across any sequence of withAttempt/withStatus.
        Assert::same($harness->delivery->getId(), $harness->originalId);
        Assert::same($harness->delivery->getEventId(), $event->getId());
        Assert::same($harness->delivery->getEventType(), $event->getType());
        Assert::same($harness->delivery->getEndpointUrl(), $endpoint->getUrl());

        $finalStatus = $harness->delivery->getStatus();

        // Tag the terminal status for the distribution report — surfaces a
        // regression that biases the generator away from one branch even
        // before the coverage floor below trips.
        Classify::when(
            condition: $finalStatus === WebhookDeliveryStatus::Delivered,
            label: 'terminal:delivered',
        );
        Classify::when(
            condition: $finalStatus === WebhookDeliveryStatus::Failed,
            label: 'terminal:failed',
        );
        Classify::when(
            condition: $finalStatus === WebhookDeliveryStatus::Pending,
            label: 'terminal:none',
        );

        // Coverage gate: across the batch, every TERMINAL status must be
        // reached. A regression that strands every sequence in Pending (e.g.
        // a broken withStatus) makes both floors miss. Pending is intentionally
        // not gated — empty sequences hit it trivially and a too-high floor
        // would be flaky on distributions that happen to dispatch a terminal
        // command first.
        Classify::cover(
            condition: $finalStatus === WebhookDeliveryStatus::Delivered,
            label: 'delivered',
            minPercent: 5.0,
        );
        Classify::cover(
            condition: $finalStatus === WebhookDeliveryStatus::Failed,
            label: 'failed',
            minPercent: 5.0,
        );

        // Swarming makes a third shape ordinary: a delivery that only ever
        // records attempts and never reaches a terminal command — the endpoint
        // that keeps timing out. Drawing all four commands uniformly, thirty
        // picks all missing both terminal actions is vanishingly rare, so this
        // was previously reachable only through the empty sequence.
        Classify::cover(
            condition: $finalStatus === WebhookDeliveryStatus::Pending && $sequence->commands !== [],
            label: 'attempts only, never terminal',
            minPercent: 5.0,
        );
    }

    /** @return array<string, ArbitraryInterface> */
    public static function lifecycleTracksModelAndKeepsIdentityImmutableGenerators(): array
    {
        return [
            // Swarmed: each sequence may use only a drawn subset of the four
            // commands, so a delivery that only ever attempts is an ordinary
            // case rather than an astronomically unlikely one. minLength stays
            // at 0, so a subset from which nothing applies yields an empty
            // sequence rather than GenerationExhausted.
            'sequence' => Gen::swarm(Gen::commands(
                new DeliveryState(status: WebhookDeliveryStatus::Pending, attempts: 0, lastError: null),
                self::commandGenerators(),
                minLength: 0,
                maxLength: 30,
            )),
        ];
    }
}
