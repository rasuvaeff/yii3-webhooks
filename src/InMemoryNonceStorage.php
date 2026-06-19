<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks;

/**
 * @api
 */
final class InMemoryNonceStorage implements NonceStorage
{
    /** @var array<string, true> */
    private array $nonces = [];

    #[\Override]
    public function has(string $nonce): bool
    {
        return isset($this->nonces[$nonce]);
    }

    #[\Override]
    public function add(string $nonce): bool
    {
        if ($this->has(nonce: $nonce)) {
            return false;
        }

        $this->nonces[$nonce] = true;

        return true;
    }

    public function clear(): void
    {
        $this->nonces = [];
    }
}
