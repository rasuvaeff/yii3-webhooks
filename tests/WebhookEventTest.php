<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;

#[CoversClass(WebhookEvent::class)]
final class WebhookEventTest extends TestCase
{
    #[Test]
    public function createsViaFactory(): void
    {
        $event = WebhookEvent::create(
            type: 'order.created',
            payload: '{"orderId":1}',
        );

        $this->assertSame('order.created', $event->getType());
        $this->assertSame('{"orderId":1}', $event->getPayload());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $event->getId());
    }

    #[Test]
    public function factoryGeneratesUniqueIds(): void
    {
        $a = WebhookEvent::create(type: 'test', payload: '{}');
        $b = WebhookEvent::create(type: 'test', payload: '{}');

        $this->assertNotSame($a->getId(), $b->getId());
    }

    #[Test]
    public function createsWithExplicitOccurredAt(): void
    {
        $at = new DateTimeImmutable('2026-06-01 10:00:00');

        $event = WebhookEvent::create(
            type: 'test',
            payload: '{}',
            occurredAt: $at,
        );

        $this->assertSame('2026-06-01 10:00:00', $event->getOccurredAt()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function throwsOnEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Event id must not be empty');

        new WebhookEvent(
            id: '',
            type: 'test',
            payload: '{}',
            occurredAt: new DateTimeImmutable(),
        );
    }

    #[Test]
    public function throwsOnEmptyType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Event type must not be empty');

        new WebhookEvent(
            id: 'some-id',
            type: '',
            payload: '{}',
            occurredAt: new DateTimeImmutable(),
        );
    }
}
