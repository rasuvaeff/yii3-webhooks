<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use InvalidArgumentException;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(WebhookEndpoint::class)]
final class WebhookEndpointTest
{
    public function createsWithRequiredFields(): void
    {
        $endpoint = new WebhookEndpoint(
            url: 'https://example.com/webhook',
            secret: 'secret123',
        );

        Assert::same($endpoint->getUrl(), 'https://example.com/webhook');
        Assert::same($endpoint->getSecret(), 'secret123');
        Assert::same($endpoint->getHeaders(), []);
    }

    public function createsWithCustomHeaders(): void
    {
        $endpoint = new WebhookEndpoint(
            url: 'https://example.com/webhook',
            secret: 'secret123',
            headers: ['X-Custom' => 'value'],
        );

        Assert::same($endpoint->getHeaders(), ['X-Custom' => 'value']);
    }

    public function acceptsHttpScheme(): void
    {
        $endpoint = new WebhookEndpoint(
            url: 'http://example.com/webhook',
            secret: 'secret',
        );

        Assert::same($endpoint->getUrl(), 'http://example.com/webhook');
    }

    public function throwsOnInvalidScheme(): void
    {
        try {
            new WebhookEndpoint(url: 'ftp://example.com', secret: 'secret');
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Endpoint URL must use http or https scheme');
        }
    }

    public function throwsOnEmptySecret(): void
    {
        try {
            new WebhookEndpoint(url: 'https://example.com', secret: '');
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Endpoint secret must not be empty');
        }
    }

    public static function invalidSchemeProvider(): iterable
    {
        yield 'ftp' => ['ftp://example.com'];
        yield 'ssh' => ['ssh://example.com'];
        yield 'no scheme' => ['example.com/webhook'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidSchemeProvider')]
    public function throwsOnNonHttpScheme(string $url): void
    {
        Expect::exception(InvalidArgumentException::class);

        new WebhookEndpoint(url: $url, secret: 'secret');
    }
}
