<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Webhooks\HmacSha256Signer;
use Rasuvaeff\Yii3Webhooks\WebhookSignature;

#[CoversClass(HmacSha256Signer::class)]
#[CoversClass(WebhookSignature::class)]
final class HmacSha256SignerTest extends TestCase
{
    private HmacSha256Signer $fixture;

    private const int TIMESTAMP = 1717228800;
    private const string SECRET = 'whsec_test';
    private const string EVENT_ID = 'evt-abc123';

    #[\Override]
    protected function setUp(): void
    {
        $this->fixture = new HmacSha256Signer();
    }

    #[Test]
    public function signProducesDeterministicSignature(): void
    {
        $a = $this->fixture->sign(payload: '{"id":1}', secret: 'secret', timestamp: self::TIMESTAMP, eventId: self::EVENT_ID);
        $b = $this->fixture->sign(payload: '{"id":1}', secret: 'secret', timestamp: self::TIMESTAMP, eventId: self::EVENT_ID);

        $this->assertSame($a->getValue(), $b->getValue());
        $this->assertSame(self::TIMESTAMP, $a->getTimestamp());
    }

    #[Test]
    public function signProducesHexString(): void
    {
        $sig = $this->fixture->sign(payload: '{}', secret: 'secret', timestamp: self::TIMESTAMP, eventId: self::EVENT_ID);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $sig->getValue());
    }

    #[Test]
    public function differentPayloadsProduceDifferentSignatures(): void
    {
        $a = $this->fixture->sign(payload: '{"id":1}', secret: 'secret', timestamp: self::TIMESTAMP, eventId: self::EVENT_ID);
        $b = $this->fixture->sign(payload: '{"id":2}', secret: 'secret', timestamp: self::TIMESTAMP, eventId: self::EVENT_ID);

        $this->assertNotSame($a->getValue(), $b->getValue());
    }

    #[Test]
    public function differentSecretsProduceDifferentSignatures(): void
    {
        $a = $this->fixture->sign(payload: '{}', secret: 'secret-a', timestamp: self::TIMESTAMP, eventId: self::EVENT_ID);
        $b = $this->fixture->sign(payload: '{}', secret: 'secret-b', timestamp: self::TIMESTAMP, eventId: self::EVENT_ID);

        $this->assertNotSame($a->getValue(), $b->getValue());
    }

    #[Test]
    public function differentTimestampsProduceDifferentSignatures(): void
    {
        $a = $this->fixture->sign(payload: '{}', secret: 'secret', timestamp: self::TIMESTAMP, eventId: self::EVENT_ID);
        $b = $this->fixture->sign(payload: '{}', secret: 'secret', timestamp: self::TIMESTAMP + 1, eventId: self::EVENT_ID);

        $this->assertNotSame($a->getValue(), $b->getValue());
    }

    #[Test]
    public function differentEventIdsProduceDifferentSignatures(): void
    {
        $a = $this->fixture->sign(payload: '{}', secret: 'secret', timestamp: self::TIMESTAMP, eventId: 'evt-aaa');
        $b = $this->fixture->sign(payload: '{}', secret: 'secret', timestamp: self::TIMESTAMP, eventId: 'evt-bbb');

        $this->assertNotSame($a->getValue(), $b->getValue());
    }

    #[Test]
    public function signatureIncludesTimestamp(): void
    {
        $sig = $this->fixture->sign(payload: '{}', secret: 'secret', timestamp: self::TIMESTAMP, eventId: self::EVENT_ID);

        $this->assertSame(self::TIMESTAMP, $sig->getTimestamp());
    }

    #[Test]
    public function knownSignatureValue(): void
    {
        // canonical: "{eventId}.{timestamp}.{payload}"
        $sig = $this->fixture->sign(payload: '{}', secret: self::SECRET, timestamp: self::TIMESTAMP, eventId: self::EVENT_ID);
        $expected = hash_hmac('sha256', self::EVENT_ID . '.' . self::TIMESTAMP . '.{}', self::SECRET);

        $this->assertSame($expected, $sig->getValue());
    }
}
