<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;
use Rasuvaeff\Yii3Webhooks\WebhookRetryPolicy;

#[CoversClass(WebhookRetryPolicy::class)]
final class WebhookRetryPolicyTest extends TestCase
{
    private WebhookRetryPolicy $fixture;
    private WebhookEvent $event;
    private WebhookEndpoint $endpoint;

    #[\Override]
    protected function setUp(): void
    {
        $this->fixture = WebhookRetryPolicy::fixed(maxAttempts: 3, delaySeconds: 60);
        $this->event = WebhookEvent::create(type: 'test', payload: '{}');
        $this->endpoint = new WebhookEndpoint(url: 'https://example.com', secret: 'secret');
    }

    private function delivery(int $attempts, ?DateTimeImmutable $lastAttemptAt = null): WebhookDelivery
    {
        $d = WebhookDelivery::create(event: $this->event, endpoint: $this->endpoint);

        for ($i = 0; $i < $attempts; $i++) {
            $d = $d->withAttempt($lastAttemptAt ?? new DateTimeImmutable());
        }

        return $d;
    }

    // ── fixed() ──────────────────────────────────────────────────────────────

    #[Test]
    public function fixedReturnsConfiguredValues(): void
    {
        $this->assertSame(3, $this->fixture->getMaxAttempts());
    }

    #[Test]
    public function fixedHasConstantDelay(): void
    {
        $this->assertSame(60, $this->fixture->nextDelaySeconds(1));
        $this->assertSame(60, $this->fixture->nextDelaySeconds(2));
        $this->assertSame(60, $this->fixture->nextDelaySeconds(3));
    }

    #[Test]
    public function fixedDefaultValues(): void
    {
        $policy = WebhookRetryPolicy::fixed();

        $this->assertSame(3, $policy->getMaxAttempts());
        $this->assertSame(60, $policy->nextDelaySeconds(1));
    }

    #[Test]
    public function shouldRetryWhenAttemptsNotExhausted(): void
    {
        $this->assertTrue($this->fixture->shouldRetry($this->delivery(attempts: 2)));
    }

    #[Test]
    public function shouldNotRetryWhenAttemptsExhausted(): void
    {
        $this->assertFalse($this->fixture->shouldRetry($this->delivery(attempts: 3)));
    }

    #[Test]
    public function shouldNotRetryWhenAlreadyDelivered(): void
    {
        $delivery = $this->delivery(attempts: 0)->withStatus(WebhookDeliveryStatus::Delivered);

        $this->assertFalse($this->fixture->shouldRetry($delivery));
    }

    #[Test]
    public function shouldNotRetryWhenFailed(): void
    {
        $delivery = $this->delivery(attempts: 0)->withStatus(WebhookDeliveryStatus::Failed);

        $this->assertFalse($this->fixture->shouldRetry($delivery));
    }

    #[Test]
    public function isReadyForRetryWithNoLastAttempt(): void
    {
        $this->assertTrue($this->fixture->isReadyForRetry($this->delivery(attempts: 0), new DateTimeImmutable()));
    }

    #[Test]
    public function isReadyAfterDelayElapsed(): void
    {
        $lastAttempt = new DateTimeImmutable('2026-06-01 12:00:00');
        $now = new DateTimeImmutable('2026-06-01 12:02:00');

        $delivery = $this->delivery(attempts: 1, lastAttemptAt: $lastAttempt);

        $this->assertTrue($this->fixture->isReadyForRetry($delivery, $now));
    }

    #[Test]
    public function isReadyExactlyWhenDelayElapsed(): void
    {
        $lastAttempt = new DateTimeImmutable('2026-06-01 12:00:00');
        $now = new DateTimeImmutable('2026-06-01 12:01:00'); // exactly 60s later

        $delivery = $this->delivery(attempts: 1, lastAttemptAt: $lastAttempt);

        $this->assertTrue($this->fixture->isReadyForRetry($delivery, $now));
    }

    #[Test]
    public function isNotReadyBeforeDelay(): void
    {
        $lastAttempt = new DateTimeImmutable('2026-06-01 12:00:00');
        $now = new DateTimeImmutable('2026-06-01 12:00:30');

        $delivery = $this->delivery(attempts: 1, lastAttemptAt: $lastAttempt);

        $this->assertFalse($this->fixture->isReadyForRetry($delivery, $now));
    }

    #[Test]
    public function isNotReadyOneSecondBeforeDelay(): void
    {
        $lastAttempt = new DateTimeImmutable('2026-06-01 12:00:00');
        $now = new DateTimeImmutable('2026-06-01 12:00:59'); // 1s before 60s delay

        $delivery = $this->delivery(attempts: 1, lastAttemptAt: $lastAttempt);

        $this->assertFalse($this->fixture->isReadyForRetry($delivery, $now));
    }

    #[Test]
    public function throwsOnMaxAttemptsLessThanOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Max attempts must be at least 1');

        WebhookRetryPolicy::fixed(maxAttempts: 0);
    }

    #[Test]
    public function allowsMaxAttemptsOfOne(): void
    {
        $policy = WebhookRetryPolicy::fixed(maxAttempts: 1);

        $this->assertSame(1, $policy->getMaxAttempts());
        $this->assertTrue($policy->shouldRetry($this->delivery(attempts: 0)));
        $this->assertFalse($policy->shouldRetry($this->delivery(attempts: 1)));
    }

    #[Test]
    public function throwsOnNegativeDelay(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Base delay seconds must be non-negative');

        WebhookRetryPolicy::fixed(delaySeconds: -1);
    }

    #[Test]
    public function allowsZeroDelay(): void
    {
        $policy = WebhookRetryPolicy::fixed(delaySeconds: 0);
        $delivery = $this->delivery(attempts: 1, lastAttemptAt: new DateTimeImmutable());

        $this->assertTrue($policy->isReadyForRetry($delivery, new DateTimeImmutable()));
    }

    #[Test]
    public function isNotReadyForRetryWhenMaxAttemptsExhausted(): void
    {
        $delivery = $this->delivery(attempts: 3);

        $this->assertFalse($this->fixture->isReadyForRetry($delivery, new DateTimeImmutable()));
    }

    // ── exponential() ────────────────────────────────────────────────────────

    #[Test]
    public function exponentialDefaultValues(): void
    {
        $policy = WebhookRetryPolicy::exponential();

        $this->assertSame(5, $policy->getMaxAttempts());
        $this->assertSame(10, $policy->nextDelaySeconds(1));
    }

    #[Test]
    public function exponentialDoublesDelayByAttempt(): void
    {
        $policy = WebhookRetryPolicy::exponential(maxAttempts: 5, baseSeconds: 10, cap: 3600);

        $this->assertSame(10, $policy->nextDelaySeconds(1));
        $this->assertSame(20, $policy->nextDelaySeconds(2));
        $this->assertSame(40, $policy->nextDelaySeconds(3));
        $this->assertSame(80, $policy->nextDelaySeconds(4));
    }

    #[Test]
    public function exponentialRespectsCapSeconds(): void
    {
        $policy = WebhookRetryPolicy::exponential(maxAttempts: 10, baseSeconds: 10, cap: 30);

        $this->assertSame(10, $policy->nextDelaySeconds(1));
        $this->assertSame(20, $policy->nextDelaySeconds(2));
        $this->assertSame(30, $policy->nextDelaySeconds(3)); // would be 40 without cap
        $this->assertSame(30, $policy->nextDelaySeconds(4)); // capped
    }

    #[Test]
    public function exponentialIsReadyAfterBaseDelay(): void
    {
        $policy = WebhookRetryPolicy::exponential(maxAttempts: 5, baseSeconds: 10, cap: 3600);
        $lastAttempt = new DateTimeImmutable('2026-06-01 12:00:00');

        $delivery = $this->delivery(attempts: 1, lastAttemptAt: $lastAttempt);

        $notYet = new DateTimeImmutable('2026-06-01 12:00:09');
        $ready  = new DateTimeImmutable('2026-06-01 12:00:10');

        $this->assertFalse($policy->isReadyForRetry($delivery, $notYet));
        $this->assertTrue($policy->isReadyForRetry($delivery, $ready));
    }

    #[Test]
    public function exponentialGrowsDelayOnSubsequentAttempts(): void
    {
        $policy = WebhookRetryPolicy::exponential(maxAttempts: 5, baseSeconds: 10, cap: 3600);
        $lastAttempt = new DateTimeImmutable('2026-06-01 12:00:00');

        $delivery = $this->delivery(attempts: 2, lastAttemptAt: $lastAttempt);

        // After 2 attempts: delay = 10 * 2^1 = 20s
        $notYet = new DateTimeImmutable('2026-06-01 12:00:19');
        $ready  = new DateTimeImmutable('2026-06-01 12:00:20');

        $this->assertFalse($policy->isReadyForRetry($delivery, $notYet));
        $this->assertTrue($policy->isReadyForRetry($delivery, $ready));
    }

    #[Test]
    public function throwsWhenCapLessThanBase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cap seconds must be >= base delay seconds');

        WebhookRetryPolicy::exponential(baseSeconds: 10, cap: 5);
    }

    #[Test]
    public function throwsOnMultiplierLessThanOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Multiplier must be at least 1.0');

        WebhookRetryPolicy::exponential(multiplier: 0.5);
    }

    #[Test]
    public function nextDelayRoundsToNearestWhenFractionalPartIsLessThanHalf(): void
    {
        // 7 * 1.3^3 = 15.379 → round() = 15, ceil() = 16, floor() = 15
        $policy = WebhookRetryPolicy::exponential(maxAttempts: 5, baseSeconds: 7, cap: 100, multiplier: 1.3);

        $this->assertSame(15, $policy->nextDelaySeconds(4));
    }

    #[Test]
    public function nextDelayRoundsUpWhenFractionalPartIsHalf(): void
    {
        // 10 * 1.5^2 = 22.5 → round() = 23, floor() = 22
        $policy = WebhookRetryPolicy::exponential(maxAttempts: 5, baseSeconds: 10, cap: 3600, multiplier: 1.5);

        $this->assertSame(23, $policy->nextDelaySeconds(3));
    }

    #[Test]
    public function exponentialDefaultCapIsThreeThousandSixHundred(): void
    {
        // 10 * 2^9 = 5120 > 3600 — capped
        $policy = WebhookRetryPolicy::exponential();

        $this->assertSame(3600, $policy->nextDelaySeconds(10));
    }
}
