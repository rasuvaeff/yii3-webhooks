<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use Rasuvaeff\Yii3Webhooks\InMemoryDeliveryStorage;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(InMemoryDeliveryStorage::class)]
final class InMemoryDeliveryStorageTest
{
    private InMemoryDeliveryStorage $fixture;
    private WebhookEvent $event;
    private WebhookEndpoint $endpoint;

    #[BeforeTest]
    public function setUp(): void
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

    public function savesAndRetrievesDelivery(): void
    {
        $delivery = WebhookDelivery::create(event: $this->event, endpoint: $this->endpoint);

        $this->fixture->save($delivery);

        $retrieved = $this->fixture->getById($delivery->getId());

        Assert::notNull($retrieved);
        Assert::same($retrieved->getId(), $delivery->getId());
    }

    public function returnsNullForUnknownId(): void
    {
        Assert::null($this->fixture->getById('nonexistent'));
    }

    public function findPendingReturnsOnlyPending(): void
    {
        $pending = $this->delivery('del-1');
        $delivered = $this->delivery('del-2')->withStatus(WebhookDeliveryStatus::Delivered);

        $this->fixture->save($pending);
        $this->fixture->save($delivered);

        $result = $this->fixture->findPending();

        Assert::count($result, 1);
        Assert::same($result[0]->getId(), 'del-1');
    }

    public function findPendingRespectsLimit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->fixture->save($this->delivery('del-' . $i));
        }

        $result = $this->fixture->findPending(limit: 3);

        Assert::count($result, 3);
    }

    public function markDeliveredUpdatesStatus(): void
    {
        $delivery = $this->delivery('del-1');

        $this->fixture->save($delivery);
        $this->fixture->markDelivered($delivery);

        $retrieved = $this->fixture->getById('del-1');

        Assert::notNull($retrieved);
        Assert::same($retrieved->getStatus(), WebhookDeliveryStatus::Delivered);
    }

    public function markFailedUpdatesStatus(): void
    {
        $delivery = $this->delivery('del-1');

        $this->fixture->save($delivery);
        $this->fixture->markFailed($delivery);

        $retrieved = $this->fixture->getById('del-1');

        Assert::notNull($retrieved);
        Assert::same($retrieved->getStatus(), WebhookDeliveryStatus::Failed);
    }

    public function countReturnsNumberOfDeliveries(): void
    {
        Assert::same($this->fixture->count(), 0);

        $this->fixture->save($this->delivery('del-1'));
        $this->fixture->save($this->delivery('del-2'));

        Assert::same($this->fixture->count(), 2);
    }

    public function clearRemovesAllDeliveries(): void
    {
        $this->fixture->save($this->delivery('del-1'));
        $this->fixture->clear();

        Assert::same($this->fixture->count(), 0);
    }

    public function iteratesOverDeliveries(): void
    {
        $this->fixture->save($this->delivery('del-1'));
        $this->fixture->save($this->delivery('del-2'));

        $ids = [];

        foreach ($this->fixture as $delivery) {
            $ids[] = $delivery->getId();
        }

        Assert::same($ids, ['del-1', 'del-2']);
    }

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

        Assert::same($result[0]->getId(), 'del-older');
        Assert::same($result[1]->getId(), 'del-newer');
    }

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

        Assert::same($result[0]->getId(), 'del-aaa');
        Assert::same($result[1]->getId(), 'del-zzz');
    }

    public function findPendingRespectsDefaultLimitOf100(): void
    {
        for ($i = 1; $i <= 101; $i++) {
            $this->fixture->save($this->delivery('del-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT)));
        }

        Assert::count($this->fixture->findPending(), 100);
    }

    public function countIsAccessibleViaCountable(): void
    {
        $this->fixture->save($this->delivery('del-1'));
        $this->fixture->save($this->delivery('del-2'));

        Assert::same(count($this->fixture), 2);
    }
}
