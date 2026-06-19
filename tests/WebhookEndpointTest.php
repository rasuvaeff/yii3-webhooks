<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;

#[CoversClass(WebhookEndpoint::class)]
final class WebhookEndpointTest extends TestCase
{
    #[Test]
    public function createsWithRequiredFields(): void
    {
        $endpoint = new WebhookEndpoint(
            url: 'https://example.com/webhook',
            secret: 'secret123',
        );

        $this->assertSame('https://example.com/webhook', $endpoint->getUrl());
        $this->assertSame('secret123', $endpoint->getSecret());
        $this->assertSame([], $endpoint->getHeaders());
    }

    #[Test]
    public function createsWithCustomHeaders(): void
    {
        $endpoint = new WebhookEndpoint(
            url: 'https://example.com/webhook',
            secret: 'secret123',
            headers: ['X-Custom' => 'value'],
        );

        $this->assertSame(['X-Custom' => 'value'], $endpoint->getHeaders());
    }

    #[Test]
    public function acceptsHttpScheme(): void
    {
        $endpoint = new WebhookEndpoint(
            url: 'http://example.com/webhook',
            secret: 'secret',
        );

        $this->assertSame('http://example.com/webhook', $endpoint->getUrl());
    }

    #[Test]
    public function throwsOnInvalidScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Endpoint URL must use http or https scheme');

        new WebhookEndpoint(url: 'ftp://example.com', secret: 'secret');
    }

    #[Test]
    public function throwsOnEmptySecret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Endpoint secret must not be empty');

        new WebhookEndpoint(url: 'https://example.com', secret: '');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSchemeProvider(): iterable
    {
        yield 'ftp' => ['ftp://example.com'];
        yield 'ssh' => ['ssh://example.com'];
        yield 'no scheme' => ['example.com/webhook'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('invalidSchemeProvider')]
    public function throwsOnNonHttpScheme(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WebhookEndpoint(url: $url, secret: 'secret');
    }
}
