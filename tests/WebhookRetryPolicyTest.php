<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;
use Rasuvaeff\Yii3Webhooks\WebhookRetryPolicy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(WebhookRetryPolicy::class)]
final class WebhookRetryPolicyTest
{
    private WebhookRetryPolicy $fixture;
    private WebhookEvent $event;
    private WebhookEndpoint $endpoint;

    #[BeforeTest]
    public function setUp(): void
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

    public function fixedReturnsConfiguredValues(): void
    {
        Assert::same($this->fixture->getMaxAttempts(), 3);
    }

    public function fixedHasConstantDelay(): void
    {
        Assert::same($this->fixture->nextDelaySeconds(1), 60);
        Assert::same($this->fixture->nextDelaySeconds(2), 60);
        Assert::same($this->fixture->nextDelaySeconds(3), 60);
    }

    public function fixedDefaultValues(): void
    {
        $policy = WebhookRetryPolicy::fixed();

        Assert::same($policy->getMaxAttempts(), 3);
        Assert::same($policy->nextDelaySeconds(1), 60);
    }

    public function shouldRetryWhenAttemptsNotExhausted(): void
    {
        Assert::true($this->fixture->shouldRetry($this->delivery(attempts: 2)));
    }

    public function shouldNotRetryWhenAttemptsExhausted(): void
    {
        Assert::false($this->fixture->shouldRetry($this->delivery(attempts: 3)));
    }

    public function shouldNotRetryWhenAlreadyDelivered(): void
    {
        $delivery = $this->delivery(attempts: 0)->withStatus(WebhookDeliveryStatus::Delivered);

        Assert::false($this->fixture->shouldRetry($delivery));
    }

    public function shouldNotRetryWhenFailed(): void
    {
        $delivery = $this->delivery(attempts: 0)->withStatus(WebhookDeliveryStatus::Failed);

        Assert::false($this->fixture->shouldRetry($delivery));
    }

    public function isReadyForRetryWithNoLastAttempt(): void
    {
        Assert::true($this->fixture->isReadyForRetry($this->delivery(attempts: 0), new DateTimeImmutable()));
    }

    public function isReadyAfterDelayElapsed(): void
    {
        $lastAttempt = new DateTimeImmutable('2026-06-01 12:00:00');
        $now = new DateTimeImmutable('2026-06-01 12:02:00');

        $delivery = $this->delivery(attempts: 1, lastAttemptAt: $lastAttempt);

        Assert::true($this->fixture->isReadyForRetry($delivery, $now));
    }

    public function isReadyExactlyWhenDelayElapsed(): void
    {
        $lastAttempt = new DateTimeImmutable('2026-06-01 12:00:00');
        $now = new DateTimeImmutable('2026-06-01 12:01:00'); // exactly 60s later

        $delivery = $this->delivery(attempts: 1, lastAttemptAt: $lastAttempt);

        Assert::true($this->fixture->isReadyForRetry($delivery, $now));
    }

    public function isNotReadyBeforeDelay(): void
    {
        $lastAttempt = new DateTimeImmutable('2026-06-01 12:00:00');
        $now = new DateTimeImmutable('2026-06-01 12:00:30');

        $delivery = $this->delivery(attempts: 1, lastAttemptAt: $lastAttempt);

        Assert::false($this->fixture->isReadyForRetry($delivery, $now));
    }

    public function isNotReadyOneSecondBeforeDelay(): void
    {
        $lastAttempt = new DateTimeImmutable('2026-06-01 12:00:00');
        $now = new DateTimeImmutable('2026-06-01 12:00:59'); // 1s before 60s delay

        $delivery = $this->delivery(attempts: 1, lastAttemptAt: $lastAttempt);

        Assert::false($this->fixture->isReadyForRetry($delivery, $now));
    }

    public function throwsOnMaxAttemptsLessThanOne(): void
    {
        try {
            WebhookRetryPolicy::fixed(maxAttempts: 0);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Max attempts must be at least 1');
        }
    }

    public function allowsMaxAttemptsOfOne(): void
    {
        $policy = WebhookRetryPolicy::fixed(maxAttempts: 1);

        Assert::same($policy->getMaxAttempts(), 1);
        Assert::true($policy->shouldRetry($this->delivery(attempts: 0)));
        Assert::false($policy->shouldRetry($this->delivery(attempts: 1)));
    }

    public function throwsOnNegativeDelay(): void
    {
        try {
            WebhookRetryPolicy::fixed(delaySeconds: -1);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Base delay seconds must be non-negative');
        }
    }

    public function allowsZeroDelay(): void
    {
        $policy = WebhookRetryPolicy::fixed(delaySeconds: 0);
        $delivery = $this->delivery(attempts: 1, lastAttemptAt: new DateTimeImmutable());

        Assert::true($policy->isReadyForRetry($delivery, new DateTimeImmutable()));
    }

    public function isNotReadyForRetryWhenMaxAttemptsExhausted(): void
    {
        $delivery = $this->delivery(attempts: 3);

        Assert::false($this->fixture->isReadyForRetry($delivery, new DateTimeImmutable()));
    }

    // ── exponential() ────────────────────────────────────────────────────────

    public function exponentialDefaultValues(): void
    {
        $policy = WebhookRetryPolicy::exponential();

        Assert::same($policy->getMaxAttempts(), 5);
        Assert::same($policy->nextDelaySeconds(1), 10);
    }

    public function exponentialDoublesDelayByAttempt(): void
    {
        $policy = WebhookRetryPolicy::exponential(maxAttempts: 5, baseSeconds: 10, cap: 3600);

        Assert::same($policy->nextDelaySeconds(1), 10);
        Assert::same($policy->nextDelaySeconds(2), 20);
        Assert::same($policy->nextDelaySeconds(3), 40);
        Assert::same($policy->nextDelaySeconds(4), 80);
    }

    public function exponentialRespectsCapSeconds(): void
    {
        $policy = WebhookRetryPolicy::exponential(maxAttempts: 10, baseSeconds: 10, cap: 30);

        Assert::same($policy->nextDelaySeconds(1), 10);
        Assert::same($policy->nextDelaySeconds(2), 20);
        Assert::same($policy->nextDelaySeconds(3), 30); // would be 40 without cap
        Assert::same($policy->nextDelaySeconds(4), 30); // capped
    }

    public function exponentialIsReadyAfterBaseDelay(): void
    {
        $policy = WebhookRetryPolicy::exponential(maxAttempts: 5, baseSeconds: 10, cap: 3600);
        $lastAttempt = new DateTimeImmutable('2026-06-01 12:00:00');

        $delivery = $this->delivery(attempts: 1, lastAttemptAt: $lastAttempt);

        $notYet = new DateTimeImmutable('2026-06-01 12:00:09');
        $ready  = new DateTimeImmutable('2026-06-01 12:00:10');

        Assert::false($policy->isReadyForRetry($delivery, $notYet));
        Assert::true($policy->isReadyForRetry($delivery, $ready));
    }

    public function exponentialGrowsDelayOnSubsequentAttempts(): void
    {
        $policy = WebhookRetryPolicy::exponential(maxAttempts: 5, baseSeconds: 10, cap: 3600);
        $lastAttempt = new DateTimeImmutable('2026-06-01 12:00:00');

        $delivery = $this->delivery(attempts: 2, lastAttemptAt: $lastAttempt);

        // After 2 attempts: delay = 10 * 2^1 = 20s
        $notYet = new DateTimeImmutable('2026-06-01 12:00:19');
        $ready  = new DateTimeImmutable('2026-06-01 12:00:20');

        Assert::false($policy->isReadyForRetry($delivery, $notYet));
        Assert::true($policy->isReadyForRetry($delivery, $ready));
    }

    public function throwsWhenCapLessThanBase(): void
    {
        try {
            WebhookRetryPolicy::exponential(baseSeconds: 10, cap: 5);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Cap seconds must be >= base delay seconds');
        }
    }

    public function throwsOnMultiplierLessThanOne(): void
    {
        try {
            WebhookRetryPolicy::exponential(multiplier: 0.5);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Multiplier must be at least 1.0');
        }
    }

    public function nextDelayRoundsToNearestWhenFractionalPartIsLessThanHalf(): void
    {
        // 7 * 1.3^3 = 15.379 → round() = 15, ceil() = 16, floor() = 15
        $policy = WebhookRetryPolicy::exponential(maxAttempts: 5, baseSeconds: 7, cap: 100, multiplier: 1.3);

        Assert::same($policy->nextDelaySeconds(4), 15);
    }

    public function nextDelayRoundsUpWhenFractionalPartIsHalf(): void
    {
        // 10 * 1.5^2 = 22.5 → round() = 23, floor() = 22
        $policy = WebhookRetryPolicy::exponential(maxAttempts: 5, baseSeconds: 10, cap: 3600, multiplier: 1.5);

        Assert::same($policy->nextDelaySeconds(3), 23);
    }

    public function exponentialDefaultCapIsThreeThousandSixHundred(): void
    {
        // 10 * 2^9 = 5120 > 3600 — capped
        $policy = WebhookRetryPolicy::exponential();

        Assert::same($policy->nextDelaySeconds(10), 3600);
    }

    #[Property(runs: 400)]
    public function nextDelayStaysWithinZeroAndCap(int $maxAttempts, int $baseSeconds, int $capExtra, float $multiplier, int $attempts): void
    {
        $policy = WebhookRetryPolicy::exponential(
            maxAttempts: $maxAttempts,
            baseSeconds: $baseSeconds,
            cap: $baseSeconds + $capExtra,
            multiplier: $multiplier,
        );
        $delay = $policy->nextDelaySeconds($attempts);

        Assert::true($delay >= 0);
        Assert::true($delay <= $baseSeconds + $capExtra);
    }

    /** @return array<string, ArbitraryInterface> */
    private function nextDelayStaysWithinZeroAndCapGenerators(): array
    {
        return [
            'maxAttempts' => Gen::intBetween(1, 50),
            'baseSeconds' => Gen::intBetween(0, 300),
            'capExtra' => Gen::intBetween(0, 3_300),
            'multiplier' => Gen::floatBetween(1.0, 3.0),
            'attempts' => Gen::intBetween(1, 30),
        ];
    }

    #[Property(runs: 300)]
    public function exhaustedDeliveryIsNeverRetried(int $maxAttempts, int $extra): void
    {
        $policy = WebhookRetryPolicy::fixed(maxAttempts: $maxAttempts);
        $delivery = $this->delivery(attempts: $maxAttempts + $extra);

        Assert::false($policy->shouldRetry($delivery));
    }

    /** @return array<string, ArbitraryInterface> */
    private function exhaustedDeliveryIsNeverRetriedGenerators(): array
    {
        return [
            'maxAttempts' => Gen::intBetween(1, 8),
            'extra' => Gen::intBetween(0, 5),
        ];
    }

    #[Property(runs: 300)]
    public function pendingDeliveryBelowMaxIsRetried(int $attempts, int $slack): void
    {
        $maxAttempts = $attempts + $slack;
        $policy = WebhookRetryPolicy::fixed(maxAttempts: $maxAttempts);
        $delivery = $this->delivery(attempts: $attempts);

        Assert::true($policy->shouldRetry($delivery));
    }

    /** @return array<string, ArbitraryInterface> */
    private function pendingDeliveryBelowMaxIsRetriedGenerators(): array
    {
        return [
            'attempts' => Gen::intBetween(0, 8),
            'slack' => Gen::intBetween(1, 5),
        ];
    }
}
