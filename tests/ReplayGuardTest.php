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
     *
     * The guard is built inside the body, NOT reused from {@see setUp()}:
     * ReplayGuard accumulates nonces across calls, and a shared instance
     * would carry state from earlier property runs (or earlier unit tests
     * in this class), making the second accept() throw RuntimeException on
     * a duplicate instead of exercising the property.
     */
    #[Property(runs: 200)]
    public function acceptedNonceIsAlwaysReportedAsReplayed(string $nonce): void
    {
        $guard = new ReplayGuard(new InMemoryNonceStorage());
        $guard->accept($nonce);

        Assert::true($guard->isReplayed($nonce));
        Assert::true($guard->isReplayed($nonce));
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
     *
     * Fresh guard per body — see {@see acceptedNonceIsAlwaysReportedAsReplayed}
     * for why the shared fixture would leak state.
     */
    #[Property(runs: 200)]
    public function distinctNoncesAreIndependent(string $a, string $b): void
    {
        Assume::that($a !== $b);

        $guard = new ReplayGuard(new InMemoryNonceStorage());
        $guard->accept($a);

        Assert::false($guard->isReplayed($b));
    }

    /** @return array<string, ArbitraryInterface> */
    public static function distinctNoncesAreIndependentGenerators(): array
    {
        // Wider alphabet than the minimum two-char set: lowers collision rate
        // between $a and $b, which would otherwise hit Assume::that discard
        // budget on the rare same-string draw.
        return [
            'a' => Gen::stringFrom(alphabet: 'abcdefghijklmnopqrstuvwxyz0123456789', minLength: 1, maxLength: 12),
            'b' => Gen::stringFrom(alphabet: 'abcdefghijklmnopqrstuvwxyz0123456789', minLength: 1, maxLength: 12),
        ];
    }
}
