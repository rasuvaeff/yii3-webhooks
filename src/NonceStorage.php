<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks;

/**
 * @api
 */
interface NonceStorage
{
    public function has(string $nonce): bool;

    /**
     * Atomically stores a nonce.
     *
     * Returns true when the nonce was inserted, false when it already existed.
     */
    public function add(string $nonce): bool;
}
