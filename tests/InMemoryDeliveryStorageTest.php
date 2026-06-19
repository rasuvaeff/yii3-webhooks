<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Webhooks\InMemoryDeliveryStorage;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;

#[CoversClass(InMemoryDeliveryStorage::class)]
final class InMemoryDeliveryStorageTest extends TestCase
{
    private InMemoryDeliveryStorage $fixture;
    private WebhookEvent $event;
    private WebhookEndpoint $endpoint;

    #[\Override]
    protected function setUp(): void
    {
        $this->fixture = new InMemoryDeliveryStorage();
        $this->event = WebhookEvent::create(type: 'test', payload: '{}');
        $this->endpoint = new WebhookEndpoint(url: 'https://example.com', secret: 'secret');
    }

    private function delivery(string $id): WebhookDelivery
    {
        return new WebhookDelivery(
            id: $id,
            eventId: 'evt-1',
            eventType: 'test',
            endpointUrl: 'https://example.com',
            status: WebhookDeliveryStatus::Pending,
            createdAt: new \DateTimeImmutable(),
        );
    }

    #[Test]
    public function savesAndRetrievesDelivery(): void
    {
        $delivery = WebhookDelivery::create(event: $this->event, endpoint: $this->endpoint);

        $this->fixture->save($delivery);

        $retrieved = $this->fixture->getById($delivery->getId());

        $this->assertNotNull($retrieved);
        $this->assertSame($delivery->getId(), $retrieved->getId());
    }

    #[Test]
    public function returnsNullForUnknownId(): void
    {
        $this->assertNull($this->fixture->getById('nonexistent'));
    }

    #[Test]
    public function findPendingReturnsOnlyPending(): void
    {
        $pending = $this->delivery('del-1');
        $delivered = $this->delivery('del-2')->withStatus(WebhookDeliveryStatus::Delivered);

        $this->fixture->save($pending);
        $this->fixture->save($delivered);

        $result = $this->fixture->findPending();

        $this->assertCount(1, $result);
        $this->assertSame('del-1', $result[0]->getId());
    }

    #[Test]
    public function findPendingRespectsLimit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->fixture->save($this->delivery('del-' . $i));
        }

        $result = $this->fixture->findPending(limit: 3);

        $this->assertCount(3, $result);
    }

    #[Test]
    public function markDeliveredUpdatesStatus(): void
    {
        $delivery = $this->delivery('del-1');

        $this->fixture->save($delivery);
        $this->fixture->markDelivered($delivery);

        $retrieved = $this->fixture->getById('del-1');

        $this->assertNotNull($retrieved);
        $this->assertSame(WebhookDeliveryStatus::Delivered, $retrieved->getStatus());
    }

    #[Test]
    public function markFailedUpdatesStatus(): void
    {
        $delivery = $this->delivery('del-1');

        $this->fixture->save($delivery);
        $this->fixture->markFailed($delivery);

        $retrieved = $this->fixture->getById('del-1');

        $this->assertNotNull($retrieved);
        $this->assertSame(WebhookDeliveryStatus::Failed, $retrieved->getStatus());
    }

    #[Test]
    public function countReturnsNumberOfDeliveries(): void
    {
        $this->assertSame(0, $this->fixture->count());

        $this->fixture->save($this->delivery('del-1'));
        $this->fixture->save($this->delivery('del-2'));

        $this->assertSame(2, $this->fixture->count());
    }

    #[Test]
    public function clearRemovesAllDeliveries(): void
    {
        $this->fixture->save($this->delivery('del-1'));
        $this->fixture->clear();

        $this->assertSame(0, $this->fixture->count());
    }

    #[Test]
    public function iteratesOverDeliveries(): void
    {
        $this->fixture->save($this->delivery('del-1'));
        $this->fixture->save($this->delivery('del-2'));

        $ids = [];

        foreach ($this->fixture as $delivery) {
            $ids[] = $delivery->getId();
        }

        $this->assertSame(['del-1', 'del-2'], $ids);
    }

    #[Test]
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

        $this->assertSame('del-older', $result[0]->getId());
        $this->assertSame('del-newer', $result[1]->getId());
    }

    #[Test]
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

        $this->assertSame('del-aaa', $result[0]->getId());
        $this->assertSame('del-zzz', $result[1]->getId());
    }

    #[Test]
    public function findPendingRespectsDefaultLimitOf100(): void
    {
        for ($i = 1; $i <= 101; $i++) {
            $this->fixture->save($this->delivery('del-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT)));
        }

        $this->assertCount(100, $this->fixture->findPending());
    }

    #[Test]
    public function countIsAccessibleViaCountable(): void
    {
        $this->fixture->save($this->delivery('del-1'));
        $this->fixture->save($this->delivery('del-2'));

        $this->assertSame(2, count($this->fixture));
    }
}
