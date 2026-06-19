<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks;

/**
 * @api
 */
interface WebhookDispatcher
{
    public function dispatch(WebhookEvent $event, WebhookEndpoint $endpoint): WebhookDelivery;
}
