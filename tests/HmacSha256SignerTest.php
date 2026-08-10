<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Webhooks\HmacSha256Signer;
use Rasuvaeff\Yii3Webhooks\WebhookSignature;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(HmacSha256Signer::class)]
#[Covers(WebhookSignature::class)]
final class HmacSha256SignerTest
{
    private HmacSha256Signer $fixture;

    private const int TIMESTAMP = 1717228800;
    private const string SECRET = 'whsec_test';
    private const string EVENT_ID = 'evt-abc123';

    #[BeforeTest]
    public function setUp(): void
    {
        $this->fixture = new HmacSha256Signer();
    }

    public function signProducesDeterministicSignature(): void
    {
        $a = $this->fixture->sign(payload: '{"id":1}', secret: 'secret', timestamp: self::TIMESTAMP, eventId: self::EVENT_ID);
        $b = $this->fixture->sign(payload: '{"id":1}', secret: 'secret', timestamp: self::TIMESTAMP, eventId: self::EVENT_ID);

        Assert::same($b->getValue(), $a->getValue());
        Assert::same($a->getTimestamp(), self::TIMESTAMP);
    }

    public function signProducesHexString(): void
    {
        $sig = $this->fixture->sign(payload: '{}', secret: 'secret', timestamp: self::TIMESTAMP, eventId: self::EVENT_ID);

        Assert::true(preg_match('/^[0-9a-f]{64}$/', $sig->getValue()) === 1);
    }

    public function differentPayloadsProduceDifferentSignatures(): void
    {
        $a = $this->fixture->sign(payload: '{"id":1}', secret: 'secret', timestamp: self::TIMESTAMP, eventId: self::EVENT_ID);
        $b = $this->fixture->sign(payload: '{"id":2}', secret: 'secret', timestamp: self::TIMESTAMP, eventId: self::EVENT_ID);

        Assert::notSame($b->getValue(), $a->getValue());
    }

    public function differentSecretsProduceDifferentSignatures(): void
    {
        $a = $this->fixture->sign(payload: '{}', secret: 'secret-a', timestamp: self::TIMESTAMP, eventId: self::EVENT_ID);
        $b = $this->fixture->sign(payload: '{}', secret: 'secret-b', timestamp: self::TIMESTAMP, eventId: self::EVENT_ID);

        Assert::notSame($b->getValue(), $a->getValue());
    }

    public function differentTimestampsProduceDifferentSignatures(): void
    {
        $a = $this->fixture->sign(payload: '{}', secret: 'secret', timestamp: self::TIMESTAMP, eventId: self::EVENT_ID);
        $b = $this->fixture->sign(payload: '{}', secret: 'secret', timestamp: self::TIMESTAMP + 1, eventId: self::EVENT_ID);

        Assert::notSame($b->getValue(), $a->getValue());
    }

    public function differentEventIdsProduceDifferentSignatures(): void
    {
        $a = $this->fixture->sign(payload: '{}', secret: 'secret', timestamp: self::TIMESTAMP, eventId: 'evt-aaa');
        $b = $this->fixture->sign(payload: '{}', secret: 'secret', timestamp: self::TIMESTAMP, eventId: 'evt-bbb');

        Assert::notSame($b->getValue(), $a->getValue());
    }

    public function signatureIncludesTimestamp(): void
    {
        $sig = $this->fixture->sign(payload: '{}', secret: 'secret', timestamp: self::TIMESTAMP, eventId: self::EVENT_ID);

        Assert::same($sig->getTimestamp(), self::TIMESTAMP);
    }

    public function knownSignatureValue(): void
    {
        $sig = $this->fixture->sign(payload: '{}', secret: self::SECRET, timestamp: self::TIMESTAMP, eventId: self::EVENT_ID);
        $expected = hash_hmac('sha256', self::EVENT_ID . '.' . self::TIMESTAMP . '.{}', self::SECRET);

        Assert::same($sig->getValue(), $expected);
    }

    /**
     * HMAC-SHA256 is a pure function of (payload, secret, timestamp, eventId).
     * The signer must be deterministic across repeated calls — receivers rely
     * on this for `hash_equals()` verification.
     */
    #[Property(runs: 200)]
    public function signIsDeterministicAcrossRepeatedCalls(string $payload, string $secret, int $timestamp, string $eventId): void
    {
        $a = $this->fixture->sign(payload: $payload, secret: $secret, timestamp: $timestamp, eventId: $eventId);
        $b = $this->fixture->sign(payload: $payload, secret: $secret, timestamp: $timestamp, eventId: $eventId);

        Assert::same($b->getValue(), $a->getValue());
        Assert::same($b->getTimestamp(), $timestamp);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function signIsDeterministicAcrossRepeatedCallsGenerators(): array
    {
        return [
            // payload: arbitrary ASCII — the signer treats it as opaque bytes
            'payload' => Gen::stringAscii(),
            // secret: non-empty ASCII; the signer does not validate it
            'secret' => Gen::stringFrom(alphabet: 'abcdefghijklmnopqrstuvwxyz0123456789!@#$', minLength: 1, maxLength: 64),
            // positive int — WebhookSignature rejects <= 0
            'timestamp' => Gen::intPositive(),
            'eventId' => Gen::stringFrom(alphabet: 'abcdefghijklmnopqrstuvwxyz0123456789-', minLength: 1, maxLength: 32),
        ];
    }

    /**
     * The signature value is always a 64-char lowercase hex string — the
     * exact format the README documents and the verifier's `hash_equals()`
     * comparison depends on.
     */
    #[Property(runs: 200)]
    public function signValueAlwaysMatches64CharLowercaseHex(string $payload, string $secret, int $timestamp, string $eventId): void
    {
        $sig = $this->fixture->sign(payload: $payload, secret: $secret, timestamp: $timestamp, eventId: $eventId);

        Assert::true(preg_match('/^[0-9a-f]{64}\z/', $sig->getValue()) === 1);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function signValueAlwaysMatches64CharLowercaseHexGenerators(): array
    {
        return [
            'payload' => Gen::stringAscii(),
            'secret' => Gen::stringFrom(alphabet: 'abcdefghijklmnopqrstuvwxyz0123456789', minLength: 1, maxLength: 32),
            'timestamp' => Gen::intPositive(),
            'eventId' => Gen::stringFrom(alphabet: 'abcdefghijklmnopqrstuvwxyz0123456789-', minLength: 1, maxLength: 32),
        ];
    }

    /**
     * Any single-byte mutation of the payload changes the signature value —
     * the canonical message includes the payload verbatim, so HMAC-SHA256's
     * avalanche effect propagates. Catches a regression that re-encodes or
     * truncates the payload before signing.
     */
    #[Property(runs: 100)]
    public function signValueChangesWhenPayloadMutated(string $payload, string $secret, int $timestamp, string $eventId, string $suffix): void
    {
        $base = $this->fixture->sign(payload: $payload, secret: $secret, timestamp: $timestamp, eventId: $eventId)->getValue();
        $mutated = $this->fixture->sign(payload: $payload . $suffix, secret: $secret, timestamp: $timestamp, eventId: $eventId)->getValue();

        Assert::notSame($mutated, $base);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function signValueChangesWhenPayloadMutatedGenerators(): array
    {
        return [
            'payload' => Gen::stringAscii(),
            'secret' => Gen::stringFrom(alphabet: 'abcdefghijklmnopqrstuvwxyz', minLength: 1, maxLength: 32),
            'timestamp' => Gen::intPositive(),
            'eventId' => Gen::stringFrom(alphabet: 'abcdefghijklmnopqrstuvwxyz', minLength: 1, maxLength: 16),
            // non-empty suffix ensures the mutated payload actually differs
            'suffix' => Gen::stringFrom(alphabet: 'X', minLength: 1, maxLength: 4),
        ];
    }
}
