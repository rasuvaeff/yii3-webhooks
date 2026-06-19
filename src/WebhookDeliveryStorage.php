<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks;

/**
 * @api
 */
interface WebhookDeliveryStorage
{
    public function save(WebhookDelivery $delivery): void;

    /**
     * @return list<WebhookDelivery>
     */
    public function findPending(int $limit = 100): array;

    public function markDelivered(WebhookDelivery $delivery): void;

    public function markFailed(WebhookDelivery $delivery): void;

    public function getById(string $id): ?WebhookDelivery;
}
