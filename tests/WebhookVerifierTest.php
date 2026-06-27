<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\Yii3Webhooks\HmacSha256Signer;
use Rasuvaeff\Yii3Webhooks\WebhookSignature;
use Rasuvaeff\Yii3Webhooks\WebhookVerifier;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;

#[Test]
#[Covers(WebhookVerifier::class)]
final class WebhookVerifierTest
{
    private HmacSha256Signer $signer;
    private StaticClock $clock;
    private WebhookVerifier $verifier;

    private const int TIMESTAMP = 1717228800;
    private const string SECRET = 'whsec_test';
    private const string PAYLOAD = '{"orderId":42}';
    private const string EVENT_ID = 'evt-abc123';

    #[BeforeTest]
    public function setUp(): void
    {
        $this->signer = new HmacSha256Signer();
        $this->clock = new StaticClock(new DateTimeImmutable('@' . self::TIMESTAMP));
        $this->verifier = new WebhookVerifier(
            signer: $this->signer,
            clock: $this->clock,
        );
    }

    public function verifiesValidSignature(): void
    {
        $signature = $this->signer->sign(
            payload: self::PAYLOAD,
            secret: self::SECRET,
            timestamp: self::TIMESTAMP,
            eventId: self::EVENT_ID,
        );

        Assert::true(
            $this->verifier->verify(
                payload: self::PAYLOAD,
                secret: self::SECRET,
                signature: $signature,
                eventId: self::EVENT_ID,
            ),
        );
    }

    public function rejectsInvalidSignatureValue(): void
    {
        $signature = new WebhookSignature(
            timestamp: self::TIMESTAMP,
            value: str_repeat('a', 64),
        );

        Assert::false(
            $this->verifier->verify(
                payload: self::PAYLOAD,
                secret: self::SECRET,
                signature: $signature,
                eventId: self::EVENT_ID,
            ),
        );
    }

    public function rejectsWrongSecret(): void
    {
        $signature = $this->signer->sign(
            payload: self::PAYLOAD,
            secret: 'wrong-secret',
            timestamp: self::TIMESTAMP,
            eventId: self::EVENT_ID,
        );

        Assert::false(
            $this->verifier->verify(
                payload: self::PAYLOAD,
                secret: self::SECRET,
                signature: $signature,
                eventId: self::EVENT_ID,
            ),
        );
    }

    public function rejectsWrongEventId(): void
    {
        $signature = $this->signer->sign(
            payload: self::PAYLOAD,
            secret: self::SECRET,
            timestamp: self::TIMESTAMP,
            eventId: 'evt-other',
        );

        Assert::false(
            $this->verifier->verify(
                payload: self::PAYLOAD,
                secret: self::SECRET,
                signature: $signature,
                eventId: self::EVENT_ID,
            ),
        );
    }

    public function rejectsExpiredTimestamp(): void
    {
        $oldTimestamp = self::TIMESTAMP - 400; // 400s ago, tolerance is 300s

        $signature = $this->signer->sign(
            payload: self::PAYLOAD,
            secret: self::SECRET,
            timestamp: $oldTimestamp,
            eventId: self::EVENT_ID,
        );

        Assert::false(
            $this->verifier->verify(
                payload: self::PAYLOAD,
                secret: self::SECRET,
                signature: $signature,
                eventId: self::EVENT_ID,
            ),
        );
    }

    public function acceptsSignatureWithinTolerance(): void
    {
        $recentTimestamp = self::TIMESTAMP - 100; // 100s ago, within 300s tolerance

        $signature = $this->signer->sign(
            payload: self::PAYLOAD,
            secret: self::SECRET,
            timestamp: $recentTimestamp,
            eventId: self::EVENT_ID,
        );

        Assert::true(
            $this->verifier->verify(
                payload: self::PAYLOAD,
                secret: self::SECRET,
                signature: $signature,
                eventId: self::EVENT_ID,
            ),
        );
    }

    public function rejectsTamperedPayload(): void
    {
        $signature = $this->signer->sign(
            payload: self::PAYLOAD,
            secret: self::SECRET,
            timestamp: self::TIMESTAMP,
            eventId: self::EVENT_ID,
        );

        Assert::false(
            $this->verifier->verify(
                payload: '{"orderId":99}',
                secret: self::SECRET,
                signature: $signature,
                eventId: self::EVENT_ID,
            ),
        );
    }

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

        Assert::false(
            $verifier->verify(
                payload: self::PAYLOAD,
                secret: self::SECRET,
                signature: $signature,
                eventId: self::EVENT_ID,
            ),
        );
    }

    public function throwsOnNegativeTolerance(): void
    {
        try {
            new WebhookVerifier(
                signer: $this->signer,
                clock: $this->clock,
                toleranceSeconds: -1,
            );
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Tolerance seconds must be non-negative');
        }
    }

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

        Assert::true(
            $verifier->verify(
                payload: self::PAYLOAD,
                secret: self::SECRET,
                signature: $signature,
                eventId: self::EVENT_ID,
            ),
        );
    }

    public function acceptsSignatureExactlyAtToleranceBoundary(): void
    {
        $timestamp = self::TIMESTAMP - 300; // age == toleranceSeconds → still accepted

        $signature = $this->signer->sign(
            payload: self::PAYLOAD,
            secret: self::SECRET,
            timestamp: $timestamp,
            eventId: self::EVENT_ID,
        );

        Assert::true(
            $this->verifier->verify(
                payload: self::PAYLOAD,
                secret: self::SECRET,
                signature: $signature,
                eventId: self::EVENT_ID,
            ),
        );
    }

    public function rejectsSignatureOneSecondOverDefaultTolerance(): void
    {
        $timestamp = self::TIMESTAMP - 301; // age = 301 > default 300

        $signature = $this->signer->sign(
            payload: self::PAYLOAD,
            secret: self::SECRET,
            timestamp: $timestamp,
            eventId: self::EVENT_ID,
        );

        Assert::false(
            $this->verifier->verify(
                payload: self::PAYLOAD,
                secret: self::SECRET,
                signature: $signature,
                eventId: self::EVENT_ID,
            ),
        );
    }
}
