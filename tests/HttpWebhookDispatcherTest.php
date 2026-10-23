<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\PinnedHttpClient\PinnedClient;
use Rasuvaeff\PinnedHttpClient\PinningFailed;
use Rasuvaeff\Yii3Webhooks\Examples\HttpWebhookDispatcher;
use Rasuvaeff\Yii3Webhooks\HmacSha256Signer;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(HttpWebhookDispatcher::class)]
final class HttpWebhookDispatcherTest
{
    private function dispatch(ClientInterface $client): \Rasuvaeff\Yii3Webhooks\WebhookDelivery
    {
        $factory = new HttpFactory();
        $dispatcher = new HttpWebhookDispatcher($client, $factory, $factory, new HmacSha256Signer(), new class implements ClockInterface {
            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01 00:00:00 UTC');
            }
        });

        return $dispatcher->dispatch(
            WebhookEvent::create(type: 'order.created', payload: '{"orderId":42}'),
            new WebhookEndpoint(url: 'https://partner.example.com/webhook', secret: 'whsec_test_secret'),
        );
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function privateResolutionProvider(): iterable
    {
        yield 'private range' => [['10.0.0.5']];
        yield 'cloud metadata address' => [['169.254.169.254']];
        yield 'loopback' => [['127.0.0.1']];
        yield 'one private among public ones' => [['93.184.216.34', '192.168.1.10']];
    }

    /**
     * @param list<string> $addresses
     */
    #[DataProvider('privateResolutionProvider')]
    public function aHostNameThatResolvesIntoAPrivateNetworkIsAFailedAttemptNotAnException(array $addresses): void
    {
        $delivery = $this->dispatch(new PinnedClient(resolver: static fn(string $host): array => $addresses));

        Assert::same($delivery->getAttempts(), 1);
        Assert::same($delivery->getLastError(), HttpWebhookDispatcher::NOT_SENT);
        Assert::notSame($delivery->getStatus(), WebhookDeliveryStatus::Delivered);
        foreach ($addresses as $address) {
            Assert::false(str_contains($delivery->getLastError(), $address));
        }
    }

    public function aRefusalNeverPutsTheExceptionMessageIntoLastError(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new PinningFailed($request, 'cURL error 7: Failed to connect to partner.example.com port 443 (resolved 10.1.2.3)');
            }
        };

        $delivery = $this->dispatch($client);

        Assert::same($delivery->getLastError(), HttpWebhookDispatcher::NOT_SENT);
        Assert::false(str_contains($delivery->getLastError(), '10.1.2.3'));
        Assert::false(str_contains($delivery->getLastError(), 'cURL'));
    }

    public function aFailedStatusIsRecordedWithoutTheResponseBody(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(500, [], 'internal: db password=hunter2 at 10.0.0.9');
            }
        };

        $delivery = $this->dispatch($client);

        Assert::same($delivery->getLastError(), 'HTTP 500');
        Assert::false(str_contains($delivery->getLastError(), 'hunter2'));
    }

    public function aSuccessfulStatusMarksTheDeliveryDelivered(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(204);
            }
        };

        $delivery = $this->dispatch($client);

        Assert::same($delivery->getStatus(), WebhookDeliveryStatus::Delivered);
        Assert::same($delivery->getLastError(), null);
    }

    public function theRequestCarriesTheSignatureAndTheEventHeaders(): void
    {
        $seen = new \ArrayObject();
        $client = new class ($seen) implements ClientInterface {
            public function __construct(private \ArrayObject $seen) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->seen['request'] = $request;

                return new Response(200);
            }
        };

        $this->dispatch($client);

        /** @var RequestInterface $request */
        $request = $seen['request'];
        Assert::same($request->getMethod(), 'POST');
        Assert::same($request->getHeaderLine('X-Webhook-Event'), 'order.created');
        Assert::true($request->hasHeader('X-Webhook-Signature'));
        Assert::same((string) $request->getBody(), '{"orderId":42}');
    }
}
