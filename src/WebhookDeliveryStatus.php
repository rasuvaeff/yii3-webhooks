<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks;

/**
 * @api
 */
enum WebhookDeliveryStatus: string
{
    case Pending   = 'pending';
    case Delivered = 'delivered';
    case Failed    = 'failed';
}
