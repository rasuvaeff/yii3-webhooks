<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use Rasuvaeff\Yii3Webhooks\InMemoryNonceStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(InMemoryNonceStorage::class)]
final class InMemoryNonceStorageTest
{
    private InMemoryNonceStorage $fixture;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->fixture = new InMemoryNonceStorage();
    }

    public function returnsFalseForUnknownNonce(): void
    {
        Assert::false($this->fixture->has('unknown'));
    }

    public function addStoresNonceAndReturnsTrue(): void
    {
        $result = $this->fixture->add('nonce-1');

        Assert::true($result);
        Assert::true($this->fixture->has('nonce-1'));
    }

    public function addReturnsFalseForDuplicateNonce(): void
    {
        $this->fixture->add('nonce-1');

        Assert::false($this->fixture->add('nonce-1'));
    }

    public function doesNotAffectOtherNonces(): void
    {
        $this->fixture->add('nonce-1');

        Assert::false($this->fixture->has('nonce-2'));
    }

    public function clearRemovesAllNonces(): void
    {
        $this->fixture->add('nonce-1');
        $this->fixture->add('nonce-2');
        $this->fixture->clear();

        Assert::false($this->fixture->has('nonce-1'));
        Assert::false($this->fixture->has('nonce-2'));
    }
}
