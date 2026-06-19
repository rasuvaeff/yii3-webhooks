<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Webhooks\HmacSha256Signer;
use Rasuvaeff\Yii3Webhooks\WebhookSignature;
use Rasuvaeff\Yii3Webhooks\WebhookVerifier;
use Yiisoft\Test\Support\Clock\StaticClock;

#[CoversClass(WebhookVerifier::class)]
final class WebhookVerifierTest extends TestCase
{
    private HmacSha256Signer $signer;
    private StaticClock $clock;
    private WebhookVerifier $verifier;

    private const int TIMESTAMP = 1717228800;
    private const string SECRET = 'whsec_test';
    private const string PAYLOAD = '{"orderId":42}';
    private const string EVENT_ID = 'evt-abc123';

    #[\Override]
    protected function setUp(): void
    {
        $this->signer = new HmacSha256Signer();
        $this->clock = new StaticClock(new DateTimeImmutable('@' . self::TIMESTAMP));
        $this->verifier = new WebhookVerifier(
            signer: $this->signer,
            clock: $this->clock,
        );
    }

    #[Test]
    public function verifiesValidSignature(): void
    {
        $signature = $this->signer->sign(
            payload: self::PAYLOAD,
            secret: self::SECRET,
            timestamp: self::TIMESTAMP,
            eventId: self::EVENT_ID,
        );

        $this->assertTrue(
            $this->verifier->verify(
                payload: self::PAYLOAD,
                secret: self::SECRET,
                signature: $signature,
                eventId: self::EVENT_ID,
            ),
        );
    }

    #[Test]
    public function rejectsInvalidSignatureValue(): void
    {
        $signature = new WebhookSignature(
            timestamp: self::TIMESTAMP,
            value: str_repeat('a', 64),
        );

        $this->assertFalse(
            $this->verifier->verify(
                payload: self::PAYLOAD,
                secret: self::SECRET,
                signature: $signature,
                eventId: self::EVENT_ID,
            ),
        );
    }

    #[Test]
    public function rejectsWrongSecret(): void
    {
        $signature = $this->signer->sign(
            payload: self::PAYLOAD,
            secret: 'wrong-secret',
            timestamp: self::TIMESTAMP,
            eventId: self::EVENT_ID,
        );

        $this->assertFalse(
            $this->verifier->verify(
                payload: self::PAYLOAD,
                secret: self::SECRET,
                signature: $signature,
                eventId: self::EVENT_ID,
            ),
        );
    }

    #[Test]
    public function rejectsWrongEventId(): void
    {
        $signature = $this->signer->sign(
            payload: self::PAYLOAD,
            secret: self::SECRET,
            timestamp: self::TIMESTAMP,
            eventId: 'evt-other',
        );

        $this->assertFalse(
            $this->verifier->verify(
                payload: self::PAYLOAD,
                secret: self::SECRET,
                signature: $signature,
                eventId: self::EVENT_ID,
            ),
        );
    }

    #[Test]
    public function rejectsExpiredTimestamp(): void
    {
        $oldTimestamp = self::TIMESTAMP - 400; // 400s ago, tolerance is 300s

        $signature = $this->signer->sign(
            payload: self::PAYLOAD,
            secret: self::SECRET,
            timestamp: $oldTimestamp,
            eventId: self::EVENT_ID,
        );

        $this->assertFalse(
            $this->verifier->verify(
                payload: self::PAYLOAD,
                secret: self::SECRET,
                signature: $signature,
                eventId: self::EVENT_ID,
            ),
        );
    }

    #[Test]
    public function acceptsSignatureWithinTolerance(): void
    {
        $recentTimestamp = self::TIMESTAMP - 100; // 100s ago, within 300s tolerance

        $signature = $this->signer->sign(
            payload: self::PAYLOAD,
            secret: self::SECRET,
            timestamp: $recentTimestamp,
            eventId: self::EVENT_ID,
        );

        $this->assertTrue(
            $this->verifier->verify(
                payload: self::PAYLOAD,
                secret: self::SECRET,
                signature: $signature,
                eventId: self::EVENT_ID,
            ),
        );
    }

    #[Test]
    public function rejectsTamperedPayload(): void
    {
        $signature = $this->signer->sign(
            payload: self::PAYLOAD,
            secret: self::SECRET,
            timestamp: self::TIMESTAMP,
            eventId: self::EVENT_ID,
        );

        $this->assertFalse(
            $this->verifier->verify(
                payload: '{"orderId":99}',
                secret: self::SECRET,
                signature: $signature,
                eventId: self::EVENT_ID,
            ),
        );
    }

    #[Test]
    public function respectsCustomTolerance(): void
    {
        $verifier = new WebhookVerifier(
            signer: $this->signer,
            clock: $this->clock,
            toleranceSeconds: 60,
        );

        $oldTimestamp = self::TIMESTAMP - 90; // 90s ago, outside 60s tolerance

        $signature = $this->signer->sign(
            payload: self::PAYLOAD,
            secret: self::SECRET,
            timestamp: $oldTimestamp,
            eventId: self::EVENT_ID,
        );

        $this->assertFalse(
            $verifier->verify(
                payload: self::PAYLOAD,
                secret: self::SECRET,
                signature: $signature,
                eventId: self::EVENT_ID,
            ),
        );
    }

    #[Test]
    public function throwsOnNegativeTolerance(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tolerance seconds must be non-negative');

        new WebhookVerifier(
            signer: $this->signer,
            clock: $this->clock,
            toleranceSeconds: -1,
        );
    }

    #[Test]
    public function allowsZeroTolerance(): void
    {
        $verifier = new WebhookVerifier(
            signer: $this->signer,
            clock: $this->clock,
            toleranceSeconds: 0,
        );

        $signature = $this->signer->sign(
            payload: self::PAYLOAD,
            secret: self::SECRET,
            timestamp: self::TIMESTAMP,
            eventId: self::EVENT_ID,
        );

        $this->assertTrue(
            $verifier->verify(
                payload: self::PAYLOAD,
                secret: self::SECRET,
                signature: $signature,
                eventId: self::EVENT_ID,
            ),
        );
    }

    #[Test]
    public function acceptsSignatureExactlyAtToleranceBoundary(): void
    {
        $timestamp = self::TIMESTAMP - 300; // age == toleranceSeconds → still accepted

        $signature = $this->signer->sign(
            payload: self::PAYLOAD,
            secret: self::SECRET,
            timestamp: $timestamp,
            eventId: self::EVENT_ID,
        );

        $this->assertTrue(
            $this->verifier->verify(
                payload: self::PAYLOAD,
                secret: self::SECRET,
                signature: $signature,
                eventId: self::EVENT_ID,
            ),
        );
    }

    #[Test]
    public function rejectsSignatureOneSecondOverDefaultTolerance(): void
    {
        $timestamp = self::TIMESTAMP - 301; // age = 301 > default 300

        $signature = $this->signer->sign(
            payload: self::PAYLOAD,
            secret: self::SECRET,
            timestamp: $timestamp,
            eventId: self::EVENT_ID,
        );

        $this->assertFalse(
            $this->verifier->verify(
                payload: self::PAYLOAD,
                secret: self::SECRET,
                signature: $signature,
                eventId: self::EVENT_ID,
            ),
        );
    }
}
