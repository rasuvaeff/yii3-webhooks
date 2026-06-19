<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Webhooks\WebhookSignature;

#[CoversClass(WebhookSignature::class)]
final class WebhookSignatureTest extends TestCase
{
    #[Test]
    public function holdsValues(): void
    {
        $sig = new WebhookSignature(timestamp: 1717228800, value: 'abc123');

        $this->assertSame(1717228800, $sig->getTimestamp());
        $this->assertSame('abc123', $sig->getValue());
    }

    #[Test]
    public function toHeaderValue(): void
    {
        $sig = new WebhookSignature(timestamp: 1717228800, value: 'abc123def456');

        $this->assertSame('t=1717228800,v1=abc123def456', $sig->toHeaderValue());
    }

    #[Test]
    public function parsesFromHeaderValue(): void
    {
        $sig = WebhookSignature::fromHeaderValue('t=1717228800,v1=abc123def456');

        $this->assertSame(1717228800, $sig->getTimestamp());
        $this->assertSame('abc123def456', $sig->getValue());
    }

    #[Test]
    public function roundTripThroughHeaderValue(): void
    {
        $original = new WebhookSignature(timestamp: 1717228800, value: 'deadbeef');
        $restored = WebhookSignature::fromHeaderValue($original->toHeaderValue());

        $this->assertSame($original->getTimestamp(), $restored->getTimestamp());
        $this->assertSame($original->getValue(), $restored->getValue());
    }

    #[Test]
    public function throwsOnMissingFields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Signature header must contain t and v1 fields');

        WebhookSignature::fromHeaderValue('t=1717228800');
    }

    #[Test]
    public function throwsOnMalformedHeader(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WebhookSignature::fromHeaderValue('not-a-valid-header');
    }

    #[Test]
    public function throwsOnNonNumericTimestamp(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid timestamp in signature header');

        WebhookSignature::fromHeaderValue('t=abc,v1=deadbeef');
    }

    #[Test]
    public function throwsOnZeroTimestamp(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Signature timestamp must be positive');

        new WebhookSignature(timestamp: 0, value: 'abc');
    }

    #[Test]
    public function throwsOnNegativeTimestamp(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WebhookSignature(timestamp: -1, value: 'abc');
    }

    #[Test]
    public function throwsOnEmptyValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Signature value must not be empty');

        new WebhookSignature(timestamp: 1717228800, value: '');
    }

    #[Test]
    public function parsesFromHeaderValueWithSpacesAroundDelimiters(): void
    {
        $sig = WebhookSignature::fromHeaderValue('t = 1717228800 , v1 = abc123def456');

        $this->assertSame(1717228800, $sig->getTimestamp());
        $this->assertSame('abc123def456', $sig->getValue());
    }

    #[Test]
    public function throwsOnTimestampWithTrailingNonDigits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid timestamp in signature header');

        WebhookSignature::fromHeaderValue('t=1234abc,v1=deadbeef');
    }

    #[Test]
    public function throwsOnTimestampWithLeadingNonDigits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid timestamp in signature header');

        WebhookSignature::fromHeaderValue('t=abc1234,v1=deadbeef');
    }
}
