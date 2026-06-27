<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use Rasuvaeff\Yii3Webhooks\InMemoryNonceStorage;
use Rasuvaeff\Yii3Webhooks\ReplayGuard;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(ReplayGuard::class)]
final class ReplayGuardTest
{
    private ReplayGuard $fixture;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->fixture = new ReplayGuard(new InMemoryNonceStorage());
    }

    public function newNonceIsNotReplayed(): void
    {
        Assert::false($this->fixture->isReplayed('nonce-1'));
    }

    public function acceptedNonceIsReplayed(): void
    {
        $this->fixture->accept('nonce-1');

        Assert::true($this->fixture->isReplayed('nonce-1'));
    }

    public function acceptDoesNotAffectOtherNonces(): void
    {
        $this->fixture->accept('nonce-1');

        Assert::false($this->fixture->isReplayed('nonce-2'));
    }

    public function acceptThrowsOnDuplicateNonce(): void
    {
        $this->fixture->accept('nonce-1');

        try {
            $this->fixture->accept('nonce-1');
            Assert::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            Assert::string($e->getMessage())->contains('Nonce already seen: nonce-1');
        }
    }
}
