<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use InvalidArgumentException;
use Rasuvaeff\Yii3Webhooks\WebhookSignature;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(WebhookSignature::class)]
final class WebhookSignatureTest
{
    public function holdsValues(): void
    {
        $sig = new WebhookSignature(timestamp: 1717228800, value: 'abc123');

        Assert::same($sig->getTimestamp(), 1717228800);
        Assert::same($sig->getValue(), 'abc123');
    }

    public function toHeaderValue(): void
    {
        $sig = new WebhookSignature(timestamp: 1717228800, value: 'abc123def456');

        Assert::same($sig->toHeaderValue(), 't=1717228800,v1=abc123def456');
    }

    public function parsesFromHeaderValue(): void
    {
        $sig = WebhookSignature::fromHeaderValue('t=1717228800,v1=abc123def456');

        Assert::same($sig->getTimestamp(), 1717228800);
        Assert::same($sig->getValue(), 'abc123def456');
    }

    public function roundTripThroughHeaderValue(): void
    {
        $original = new WebhookSignature(timestamp: 1717228800, value: 'deadbeef');
        $restored = WebhookSignature::fromHeaderValue($original->toHeaderValue());

        Assert::same($restored->getTimestamp(), $original->getTimestamp());
        Assert::same($restored->getValue(), $original->getValue());
    }

    public function throwsOnMissingFields(): void
    {
        try {
            WebhookSignature::fromHeaderValue('t=1717228800');
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Signature header must contain t and v1 fields');
        }
    }

    public function throwsOnMalformedHeader(): void
    {
        Expect::exception(InvalidArgumentException::class);

        WebhookSignature::fromHeaderValue('not-a-valid-header');
    }

    public function throwsOnNonNumericTimestamp(): void
    {
        try {
            WebhookSignature::fromHeaderValue('t=abc,v1=deadbeef');
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Invalid timestamp in signature header');
        }
    }

    public function throwsOnZeroTimestamp(): void
    {
        try {
            new WebhookSignature(timestamp: 0, value: 'abc');
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Signature timestamp must be positive');
        }
    }

    public function throwsOnNegativeTimestamp(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new WebhookSignature(timestamp: -1, value: 'abc');
    }

    public function throwsOnEmptyValue(): void
    {
        try {
            new WebhookSignature(timestamp: 1717228800, value: '');
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Signature value must not be empty');
        }
    }

    public function parsesFromHeaderValueWithSpacesAroundDelimiters(): void
    {
        $sig = WebhookSignature::fromHeaderValue('t = 1717228800 , v1 = abc123def456');

        Assert::same($sig->getTimestamp(), 1717228800);
        Assert::same($sig->getValue(), 'abc123def456');
    }

    public function throwsOnTimestampWithTrailingNonDigits(): void
    {
        try {
            WebhookSignature::fromHeaderValue('t=1234abc,v1=deadbeef');
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Invalid timestamp in signature header');
        }
    }

    public function throwsOnTimestampWithLeadingNonDigits(): void
    {
        try {
            WebhookSignature::fromHeaderValue('t=abc1234,v1=deadbeef');
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Invalid timestamp in signature header');
        }
    }
}
