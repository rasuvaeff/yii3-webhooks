<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Assume;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
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

    /**
     * After accept(), the guard must keep reporting the nonce as replayed on
     * every subsequent isReplayed() call — there is no TTL, no eviction.
     */
    #[Property(runs: 200)]
    public function acceptedNonceIsAlwaysReportedAsReplayed(string $nonce): void
    {
        $this->fixture->accept($nonce);

        Assert::true($this->fixture->isReplayed($nonce));
        Assert::true($this->fixture->isReplayed($nonce));
    }

    /** @return array<string, ArbitraryInterface> */
    public static function acceptedNonceIsAlwaysReportedAsReplayedGenerators(): array
    {
        return [
            // ASCII to keep the nonce free of `__toString` surprises; the
            // guard treats it as an opaque string key.
            'nonce' => Gen::stringAscii(),
        ];
    }

    /**
     * @return iterable<array{0: string}>
     */
    public static function acceptedNonceIsAlwaysReportedAsReplayedExamples(): iterable
    {
        yield 'empty string (degenerate but valid key)' => [''];
        yield 'single char' => ['a'];
        yield 'typical 32-hex signature value' => ['4f3a8c2b1e0d5a6f7c9b8e2d1a4f5c6b'];
        yield 'long nonce' => [str_repeat(string: 'n', times: 1024)];
    }

    /**
     * Two distinct nonces never interfere: accepting one does not mark the
     * other as replayed, and the storage does not key on a shared prefix.
     */
    #[Property(runs: 200)]
    public function distinctNoncesAreIndependent(string $a, string $b): void
    {
        Assume::that($a !== $b);

        $this->fixture->accept($a);

        Assert::false($this->fixture->isReplayed($b));
    }

    /** @return array<string, ArbitraryInterface> */
    public static function distinctNoncesAreIndependentGenerators(): array
    {
        return [
            'a' => Gen::stringFrom(alphabet: 'ab', minLength: 1, maxLength: 8),
            'b' => Gen::stringFrom(alphabet: 'ab', minLength: 1, maxLength: 8),
        ];
    }
}
