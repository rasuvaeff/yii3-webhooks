<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Examples;

use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookDispatcher;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;
use Rasuvaeff\Yii3Webhooks\WebhookSigner;

/**
 * Example dispatcher over any PSR-18 client; `examples/dispatcher.php` wires it to `rasuvaeff/pinned-http-client`.
 *
 * What goes into `lastError` is fixed text: the status code, or "the request was not sent". The response body, the
 * exception message (a connection failure names the address that was tried) and the resolved address never get there,
 * because delivery-status APIs hand `lastError` back to whoever registered the endpoint.
 */
final readonly class HttpWebhookDispatcher implements WebhookDispatcher
{
    public const string NOT_SENT = 'Request not sent: refused by the network policy or the connection failed';

    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private WebhookSigner $signer,
        private ClockInterface $clock,
    ) {}

    #[\Override]
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
            $statusCode = $this->httpClient->sendRequest($request)->getStatusCode();
        } catch (ClientExceptionInterface) {
            return $delivery->withAttempt(at: $now, error: self::NOT_SENT);
        }

        if ($statusCode >= 200 && $statusCode < 300) {
            return $delivery
                ->withAttempt(at: $now)
                ->withStatus(status: WebhookDeliveryStatus::Delivered);
        }

        return $delivery->withAttempt(at: $now, error: 'HTTP ' . $statusCode);
    }
}
