<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests\Support;

/**
 * The three lifecycle steps a dispatcher can take against a
 * {@see \Rasuvaeff\Yii3Webhooks\WebhookDelivery}: record an attempt, mark
 * success, or give up. Backed by a string so the {@see DeliveryCommand}
 * constructor still reads naturally, and exhaustive-by-type on every
 * `match` (no default arm, no {@see \UnhandledMatchError} surface).
 */
enum DeliveryAction: string
{
    case Attempt = 'attempt';
    case Succeed = 'succeed';
    case Fail = 'fail';
}
