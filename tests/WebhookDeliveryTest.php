<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;

#[CoversClass(WebhookDelivery::class)]
final class WebhookDeliveryTest extends TestCase
{
    private WebhookEvent $event;
    private WebhookEndpoint $endpoint;

    #[\Override]
    protected function setUp(): void
    {
        $this->event = WebhookEvent::create(type: 'order.created', payload: '{"orderId":1}');
        $this->endpoint = new WebhookEndpoint(
            url: 'https://example.com/webhook',
            secret: 'secret',
        );
    }

    #[Test]
    public function createsViaDomainFactory(): void
    {
        $delivery = WebhookDelivery::create(
            event: $this->event,
            endpoint: $this->endpoint,
        );

        $this->assertSame($this->event->getId(), $delivery->getEventId());
        $this->assertSame($this->event->getType(), $delivery->getEventType());
        $this->assertSame('https://example.com/webhook', $delivery->getEndpointUrl());
        $this->assertSame(WebhookDeliveryStatus::Pending, $delivery->getStatus());
        $this->assertSame(0, $delivery->getAttempts());
        $this->assertNull($delivery->getLastAttemptAt());
        $this->assertNull($delivery->getLastError());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $delivery->getId());
    }

    #[Test]
    public function createsWithExplicitCreatedAt(): void
    {
        $at = new DateTimeImmutable('2026-06-01 10:00:00');

        $delivery = WebhookDelivery::create(
            event: $this->event,
            endpoint: $this->endpoint,
            createdAt: $at,
        );

        $this->assertSame('2026-06-01 10:00:00', $delivery->getCreatedAt()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function withAttemptIncrementsAndSetsTimestamp(): void
    {
        $delivery = WebhookDelivery::create(event: $this->event, endpoint: $this->endpoint);
        $at = new DateTimeImmutable('2026-06-01 12:00:00');

        $attempted = $delivery->withAttempt($at);

        $this->assertSame(1, $attempted->getAttempts());
        $this->assertSame(0, $delivery->getAttempts());
        $this->assertSame('2026-06-01 12:00:00', $attempted->getLastAttemptAt()?->format('Y-m-d H:i:s'));
        $this->assertNull($attempted->getLastError());
    }

    #[Test]
    public function withAttemptSetsError(): void
    {
        $delivery = WebhookDelivery::create(event: $this->event, endpoint: $this->endpoint);
        $attempted = $delivery->withAttempt(new DateTimeImmutable(), error: 'Connection refused');

        $this->assertSame('Connection refused', $attempted->getLastError());
    }

    #[Test]
    public function withStatusReturnsNewInstance(): void
    {
        $delivery = WebhookDelivery::create(event: $this->event, endpoint: $this->endpoint);
        $delivered = $delivery->withStatus(WebhookDeliveryStatus::Delivered);

        $this->assertSame(WebhookDeliveryStatus::Delivered, $delivered->getStatus());
        $this->assertSame(WebhookDeliveryStatus::Pending, $delivery->getStatus());
    }

    #[Test]
    public function throwsOnEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Delivery id must not be empty');

        new WebhookDelivery(
            id: '',
            eventId: 'evt-1',
            eventType: 'test',
            endpointUrl: 'https://example.com',
            status: WebhookDeliveryStatus::Pending,
            createdAt: new DateTimeImmutable(),
        );
    }

    #[Test]
    public function throwsOnEmptyEventId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Delivery eventId must not be empty');

        new WebhookDelivery(
            id: 'del-1',
            eventId: '',
            eventType: 'test',
            endpointUrl: 'https://example.com',
            status: WebhookDeliveryStatus::Pending,
            createdAt: new DateTimeImmutable(),
        );
    }

    #[Test]
    public function throwsOnNegativeAttempts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Attempts must be non-negative');

        new WebhookDelivery(
            id: 'del-1',
            eventId: 'evt-1',
            eventType: 'test',
            endpointUrl: 'https://example.com',
            status: WebhookDeliveryStatus::Pending,
            createdAt: new DateTimeImmutable(),
            attempts: -1,
        );
    }

    #[Test]
    public function secretNotStoredInDelivery(): void
    {
        $delivery = WebhookDelivery::create(event: $this->event, endpoint: $this->endpoint);
        $serialized = serialize($delivery);

        $this->assertSame('https://example.com/webhook', $delivery->getEndpointUrl());
        $this->assertStringNotContainsString('secret', $serialized);
    }
}
