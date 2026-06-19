<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks;

use RuntimeException;

/**
 * @api
 */
final readonly class ReplayGuard
{
    public function __construct(private NonceStorage $storage) {}

    public function isReplayed(string $nonce): bool
    {
        return $this->storage->has($nonce);
    }

    public function accept(string $nonce): void
    {
        if (!$this->storage->add(nonce: $nonce)) {
            throw new RuntimeException('Nonce already seen: ' . $nonce);
        }
    }
}
