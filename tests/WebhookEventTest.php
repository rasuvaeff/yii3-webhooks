<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(WebhookEvent::class)]
final class WebhookEventTest
{
    public function createsViaFactory(): void
    {
        $event = WebhookEvent::create(
            type: 'order.created',
            payload: '{"orderId":1}',
        );

        Assert::same($event->getType(), 'order.created');
        Assert::same($event->getPayload(), '{"orderId":1}');
        Assert::true(preg_match('/^[0-9a-f]{32}$/', $event->getId()) === 1);
    }

    public function factoryGeneratesUniqueIds(): void
    {
        $a = WebhookEvent::create(type: 'test', payload: '{}');
        $b = WebhookEvent::create(type: 'test', payload: '{}');

        Assert::notSame($b->getId(), $a->getId());
    }

    public function createsWithExplicitOccurredAt(): void
    {
        $at = new DateTimeImmutable('2026-06-01 10:00:00');

        $event = WebhookEvent::create(
            type: 'test',
            payload: '{}',
            occurredAt: $at,
        );

        Assert::same($event->getOccurredAt()->format('Y-m-d H:i:s'), '2026-06-01 10:00:00');
    }

    public function throwsOnEmptyId(): void
    {
        try {
            new WebhookEvent(
                id: '',
                type: 'test',
                payload: '{}',
                occurredAt: new DateTimeImmutable(),
            );
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Event id must not be empty');
        }
    }

    public function throwsOnEmptyType(): void
    {
        try {
            new WebhookEvent(
                id: 'some-id',
                type: '',
                payload: '{}',
                occurredAt: new DateTimeImmutable(),
            );
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Event type must not be empty');
        }
    }
}
