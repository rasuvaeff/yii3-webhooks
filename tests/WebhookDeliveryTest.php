<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(WebhookDelivery::class)]
final class WebhookDeliveryTest
{
    private WebhookEvent $event;
    private WebhookEndpoint $endpoint;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->event = WebhookEvent::create(type: 'order.created', payload: '{"orderId":1}');
        $this->endpoint = new WebhookEndpoint(
            url: 'https://example.com/webhook',
            secret: 'secret',
        );
    }

    public function createsViaDomainFactory(): void
    {
        $delivery = WebhookDelivery::create(
            event: $this->event,
            endpoint: $this->endpoint,
        );

        Assert::same($delivery->getEventId(), $this->event->getId());
        Assert::same($delivery->getEventType(), $this->event->getType());
        Assert::same($delivery->getEndpointUrl(), 'https://example.com/webhook');
        Assert::same($delivery->getStatus(), WebhookDeliveryStatus::Pending);
        Assert::same($delivery->getAttempts(), 0);
        Assert::null($delivery->getLastAttemptAt());
        Assert::null($delivery->getLastError());
        Assert::true(preg_match('/^[0-9a-f]{32}$/', $delivery->getId()) === 1);
    }

    public function createsWithExplicitCreatedAt(): void
    {
        $at = new DateTimeImmutable('2026-06-01 10:00:00');

        $delivery = WebhookDelivery::create(
            event: $this->event,
            endpoint: $this->endpoint,
            createdAt: $at,
        );

        Assert::same($delivery->getCreatedAt()->format('Y-m-d H:i:s'), '2026-06-01 10:00:00');
    }

    public function withAttemptIncrementsAndSetsTimestamp(): void
    {
        $delivery = WebhookDelivery::create(event: $this->event, endpoint: $this->endpoint);
        $at = new DateTimeImmutable('2026-06-01 12:00:00');

        $attempted = $delivery->withAttempt($at);

        Assert::same($attempted->getAttempts(), 1);
        Assert::same($delivery->getAttempts(), 0);
        Assert::same($attempted->getLastAttemptAt()?->format('Y-m-d H:i:s'), '2026-06-01 12:00:00');
        Assert::null($attempted->getLastError());
    }

    public function withAttemptSetsError(): void
    {
        $delivery = WebhookDelivery::create(event: $this->event, endpoint: $this->endpoint);
        $attempted = $delivery->withAttempt(new DateTimeImmutable(), error: 'Connection refused');

        Assert::same($attempted->getLastError(), 'Connection refused');
    }

    public function withStatusReturnsNewInstance(): void
    {
        $delivery = WebhookDelivery::create(event: $this->event, endpoint: $this->endpoint);
        $delivered = $delivery->withStatus(WebhookDeliveryStatus::Delivered);

        Assert::same($delivered->getStatus(), WebhookDeliveryStatus::Delivered);
        Assert::same($delivery->getStatus(), WebhookDeliveryStatus::Pending);
    }

    public function throwsOnEmptyId(): void
    {
        try {
            new WebhookDelivery(
                id: '',
                eventId: 'evt-1',
                eventType: 'test',
                endpointUrl: 'https://example.com',
                status: WebhookDeliveryStatus::Pending,
                createdAt: new DateTimeImmutable(),
            );
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Delivery id must not be empty');
        }
    }

    public function throwsOnEmptyEventId(): void
    {
        try {
            new WebhookDelivery(
                id: 'del-1',
                eventId: '',
                eventType: 'test',
                endpointUrl: 'https://example.com',
                status: WebhookDeliveryStatus::Pending,
                createdAt: new DateTimeImmutable(),
            );
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Delivery eventId must not be empty');
        }
    }

    public function throwsOnNegativeAttempts(): void
    {
        try {
            new WebhookDelivery(
                id: 'del-1',
                eventId: 'evt-1',
                eventType: 'test',
                endpointUrl: 'https://example.com',
                status: WebhookDeliveryStatus::Pending,
                createdAt: new DateTimeImmutable(),
                attempts: -1,
            );
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Attempts must be non-negative');
        }
    }

    public function secretNotStoredInDelivery(): void
    {
        $delivery = WebhookDelivery::create(event: $this->event, endpoint: $this->endpoint);
        $serialized = serialize($delivery);

        Assert::same($delivery->getEndpointUrl(), 'https://example.com/webhook');
        Assert::string($serialized)->notContains('secret');
    }
}
