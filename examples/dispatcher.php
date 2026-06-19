<?php

declare(strict_types=1);

/**
 * Example: implementing WebhookDispatcher with Guzzle (PSR-18).
 *
 * This library ships WebhookDispatcher as an interface only.
 * Wire in any PSR-18-compatible HTTP client — this example uses Guzzle.
 *
 * composer require guzzlehttp/guzzle guzzlehttp/psr7
 */

require __DIR__ . '/../vendor/autoload.php';

if (!class_exists('GuzzleHttp\Client')) {
    echo "This example requires Guzzle. Run:\n  composer require guzzlehttp/guzzle guzzlehttp/psr7\n";
    exit(0);
}

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Webhooks\HmacSha256Signer;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookDispatcher;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;
use Rasuvaeff\Yii3Webhooks\WebhookSigner;

final readonly class GuzzleWebhookDispatcher implements WebhookDispatcher
{
    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private WebhookSigner $signer,
        private ClockInterface $clock,
    ) {}

    public function dispatch(WebhookEvent $event, WebhookEndpoint $endpoint): WebhookDelivery
    {
        $now = $this->clock->now();
        $delivery = WebhookDelivery::create(event: $event, endpoint: $endpoint, createdAt: $now);
        $signature = $this->signer->sign(
            payload: $event->getPayload(),
            secret: $endpoint->getSecret(),
            timestamp: $now->getTimestamp(),
            eventId: $event->getId(),
        );

        $request = $this->requestFactory
            ->createRequest('POST', $endpoint->getUrl())
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Webhook-Signature', $signature->toHeaderValue())
            ->withHeader('X-Webhook-Event', $event->getType())
            ->withHeader('X-Webhook-Id', $event->getId())
            ->withBody($this->streamFactory->createStream($event->getPayload()));

        foreach ($endpoint->getHeaders() as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            $response = $this->httpClient->sendRequest($request);
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                return $delivery
                    ->withAttempt(at: $now)
                    ->withStatus(status: WebhookDeliveryStatus::Delivered);
            }

            return $delivery->withAttempt(at: $now, error: 'HTTP ' . $statusCode);
        } catch (ClientExceptionInterface $e) {
            return $delivery->withAttempt(at: $now, error: $e->getMessage());
        }
    }
}

// Usage:
$factory = new HttpFactory();
$clock = new class implements ClockInterface {
    public function now(): \DateTimeImmutable { return new \DateTimeImmutable(); }
};

$dispatcher = new GuzzleWebhookDispatcher(
    httpClient: new Client(),
    requestFactory: $factory,
    streamFactory: $factory,
    signer: new HmacSha256Signer(),
    clock: $clock,
);

$event = WebhookEvent::create(type: 'order.created', payload: json_encode(['orderId' => 42], JSON_THROW_ON_ERROR));
$endpoint = new WebhookEndpoint(url: 'https://partner.example.com/webhook', secret: 'whsec_test_secret');

$delivery = $dispatcher->dispatch($event, $endpoint);
echo "Status: {$delivery->getStatus()->value}, Attempts: {$delivery->getAttempts()}\n";
