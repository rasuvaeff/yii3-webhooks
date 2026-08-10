<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
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

    public function factoryAcceptsTheDomainEventId(): void
    {
        // receivers deduplicate on this id: republishing the same domain event
        // must not arrive as a different webhook event
        $event = WebhookEvent::create(type: 'order.created', payload: '{}', id: 'order-created-42');

        Assert::same($event->getId(), 'order-created-42');
    }

    public function factoryKeepsTheHistoricalFormatWithoutAnId(): void
    {
        Assert::same(preg_match('/^[0-9a-f]{32}$/', WebhookEvent::create(type: 't', payload: '{}')->getId()), 1);
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

    /**
     * The constructor is the storage boundary between the domain (which picks
     * the id and timestamp) and the dispatcher (which reads them back when
     * signing). Verify that getters return exactly what was passed in, for any
     * non-empty id and type, any payload including empty, and any timestamp.
     */
    #[Property(runs: 200)]
    public function constructorRoundTripsAllFields(string $id, string $type, string $payload, DateTimeImmutable $occurredAt): void
    {
        $event = new WebhookEvent(
            id: $id,
            type: $type,
            payload: $payload,
            occurredAt: $occurredAt,
        );

        Assert::same($event->getId(), $id);
        Assert::same($event->getType(), $type);
        Assert::same($event->getPayload(), $payload);
        Assert::same($event->getOccurredAt(), $occurredAt);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function constructorRoundTripsAllFieldsGenerators(): array
    {
        return [
            // non-empty — constructor rejects empty id and empty type
            'id' => Gen::stringFrom(alphabet: 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-', minLength: 1, maxLength: 32),
            'type' => Gen::stringFrom(alphabet: 'abcdefghijklmnopqrstuvwxyz.', minLength: 1, maxLength: 32),
            // payload may be empty; webhook contract does not require valid JSON here
            'payload' => Gen::stringAscii(),
            'occurredAt' => Gen::datetime(),
        ];
    }

    /**
     * @return iterable<array{0: string, 1: string, 2: string, 3: DateTimeImmutable}>
     */
    public static function constructorRoundTripsAllFieldsExamples(): iterable
    {
        yield 'minimum-length id and type, empty payload' => ['a', 't', '', new DateTimeImmutable('2026-01-01 00:00:00')];
        yield 'typical webhook event with JSON payload' => [
            'order-42',
            'order.created',
            '{"orderId":42,"total":1234}',
            new DateTimeImmutable('2026-06-15 12:34:56'),
        ];
        yield 'historical 32-hex id format' => [
            '4f3a8c2b1e0d5a6f7c9b8e2d1a4f5c6b',
            'user.deleted',
            '{"userId":99}',
            new DateTimeImmutable('2025-12-31 23:59:59'),
        ];
        yield 'payload with embedded quotes' => ['id-1', 't', '{"msg":"hi \"world\""}', new DateTimeImmutable('2026-06-01 10:00:00')];
    }

    /**
     * The factory must defer to caller-supplied id and timestamp, and only
     * generate the missing ones. Verifies the README contract that a
     * republish of the same domain event arrives under the same id.
     */
    #[Property(runs: 100)]
    public function factoryHonoursExplicitIdAndTimestamp(string $id, string $type, DateTimeImmutable $occurredAt): void
    {
        $event = WebhookEvent::create(
            type: $type,
            payload: '{}',
            occurredAt: $occurredAt,
            id: $id,
        );

        Assert::same($event->getId(), $id);
        Assert::same($event->getOccurredAt(), $occurredAt);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function factoryHonoursExplicitIdAndTimestampGenerators(): array
    {
        return [
            'id' => Gen::stringFrom(alphabet: 'abcdefghijklmnopqrstuvwxyz0123456789-', minLength: 1, maxLength: 32),
            'type' => Gen::stringFrom(alphabet: 'abcdefghijklmnopqrstuvwxyz.', minLength: 1, maxLength: 32),
            'occurredAt' => Gen::datetime(),
        ];
    }
}
