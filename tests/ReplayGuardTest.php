<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Webhooks\InMemoryNonceStorage;
use Rasuvaeff\Yii3Webhooks\ReplayGuard;
use RuntimeException;

#[CoversClass(ReplayGuard::class)]
final class ReplayGuardTest extends TestCase
{
    private ReplayGuard $fixture;

    #[\Override]
    protected function setUp(): void
    {
        $this->fixture = new ReplayGuard(new InMemoryNonceStorage());
    }

    #[Test]
    public function newNonceIsNotReplayed(): void
    {
        $this->assertFalse($this->fixture->isReplayed('nonce-1'));
    }

    #[Test]
    public function acceptedNonceIsReplayed(): void
    {
        $this->fixture->accept('nonce-1');

        $this->assertTrue($this->fixture->isReplayed('nonce-1'));
    }

    #[Test]
    public function acceptDoesNotAffectOtherNonces(): void
    {
        $this->fixture->accept('nonce-1');

        $this->assertFalse($this->fixture->isReplayed('nonce-2'));
    }

    #[Test]
    public function acceptThrowsOnDuplicateNonce(): void
    {
        $this->fixture->accept('nonce-1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nonce already seen: nonce-1');

        $this->fixture->accept('nonce-1');
    }
}
