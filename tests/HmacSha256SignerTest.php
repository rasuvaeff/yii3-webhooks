<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

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
}
