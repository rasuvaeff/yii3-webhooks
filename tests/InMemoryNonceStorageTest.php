<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Webhooks\InMemoryNonceStorage;

#[CoversClass(InMemoryNonceStorage::class)]
final class InMemoryNonceStorageTest extends TestCase
{
    private InMemoryNonceStorage $fixture;

    #[\Override]
    protected function setUp(): void
    {
        $this->fixture = new InMemoryNonceStorage();
    }

    #[Test]
    public function returnsFalseForUnknownNonce(): void
    {
        $this->assertFalse($this->fixture->has('unknown'));
    }

    #[Test]
    public function addStoresNonceAndReturnsTrue(): void
    {
        $result = $this->fixture->add('nonce-1');

        $this->assertTrue($result);
        $this->assertTrue($this->fixture->has('nonce-1'));
    }

    #[Test]
    public function addReturnsFalseForDuplicateNonce(): void
    {
        $this->fixture->add('nonce-1');

        $this->assertFalse($this->fixture->add('nonce-1'));
    }

    #[Test]
    public function doesNotAffectOtherNonces(): void
    {
        $this->fixture->add('nonce-1');

        $this->assertFalse($this->fixture->has('nonce-2'));
    }

    #[Test]
    public function clearRemovesAllNonces(): void
    {
        $this->fixture->add('nonce-1');
        $this->fixture->add('nonce-2');
        $this->fixture->clear();

        $this->assertFalse($this->fixture->has('nonce-1'));
        $this->assertFalse($this->fixture->has('nonce-2'));
    }
}
