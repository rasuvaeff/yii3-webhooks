<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use InvalidArgumentException;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
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

    #[Property(runs: 200)]
    public function headerRoundTripPreservesTimestampAndValue(int $timestamp, string $value): void
    {
        $sig = new WebhookSignature(timestamp: $timestamp, value: $value);
        $restored = WebhookSignature::fromHeaderValue(header: $sig->toHeaderValue());

        Assert::same($restored->getTimestamp(), $timestamp);
        Assert::same($restored->getValue(), $value);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function headerRoundTripPreservesTimestampAndValueGenerators(): array
    {
        return [
            // positive int — constructor rejects <= 0
            'timestamp' => Gen::intPositive(),
            // hex alphabet keeps the value free of `,` and `=`, both of which
            // would interact with the header's explode-based parser; this
            // isolates the round-trip property from the parser's known
            // delimiter handling.
            'value' => Gen::stringFrom(alphabet: '0123456789abcdef', minLength: 1, maxLength: 128),
        ];
    }

    /**
     * Boundary cases for the round-trip: minimum-positive timestamp, single-char
     * value, a realistic 64-char HMAC-SHA256 hex digest, and a long value.
     *
     * @return iterable<array{0: int, 1: string}>
     */
    public static function headerRoundTripPreservesTimestampAndValueExamples(): iterable
    {
        yield 'minimum timestamp' => [1, 'a'];
        yield 'typical HMAC-SHA256 (64 hex chars)' => [
            1_717_228_800,
            '4f3a8c2b1e0d5a6f7c9b8e2d1a4f5c6b3e2d1a0f9c8b7e6d5a4f3c2b1e0d9a8',
        ];
        yield 'long value (max HMAC + extra)' => [1_717_228_800, str_repeat(string: 'ab', times: 64)];
    }

    /**
     * Whitespace around delimiters is allowed by the parser ({@see trim()});
     * verify the parser still extracts the original values when spaces are
     * injected around `=` and `,`.
     */
    #[Property(runs: 100)]
    public function headerWithSpacesRoundTripsToSameValues(int $timestamp, string $value): void
    {
        $header = "t = {$timestamp} , v1 = {$value}";
        $sig = WebhookSignature::fromHeaderValue(header: $header);

        Assert::same($sig->getTimestamp(), $timestamp);
        Assert::same($sig->getValue(), $value);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function headerWithSpacesRoundTripsToSameValuesGenerators(): array
    {
        return [
            'timestamp' => Gen::intPositive(),
            'value' => Gen::stringFrom(alphabet: '0123456789abcdef', minLength: 1, maxLength: 64),
        ];
    }
}
