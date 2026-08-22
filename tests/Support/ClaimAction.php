<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests\Support;

/**
 * The steps a pool of workers can take against a delivery held in a
 * {@see \Rasuvaeff\Yii3Webhooks\ClaimingDeliveryStorage}: take a lease, give it
 * back, record a failed attempt, terminate the delivery, or simply let time
 * pass — which is what expires a lease and what clears a backoff.
 *
 * Backed by a string so {@see ClaimCommand::__toString()} reads naturally in a
 * counterexample, and exhaustive-by-type on every `match`.
 */
enum ClaimAction: string
{
    case Claim = 'claim';
    case Release = 'release';
    case Attempt = 'attempt';
    case Succeed = 'succeed';
    case Fail = 'fail';
    case Wait = 'wait';
}
