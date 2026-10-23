<?php

declare(strict_types=1);

/**
 * Example: a WebhookDispatcher that is safe against SSRF (PSR-18).
 *
 * This library ships WebhookDispatcher as an interface only. The dispatcher in `HttpWebhookDispatcher.php` takes any
 * PSR-18 client; the client decides what the destination may be. `rasuvaeff/pinned-http-client` resolves the host name
 * at delivery time, checks every address it got, pins the connection to the checked addresses (no DNS rebinding between
 * the check and the connect) and never follows redirects. A refusal is a failed attempt, not an exception.
 *
 * composer require rasuvaeff/pinned-http-client
 */

require __DIR__ . '/../vendor/autoload.php';

if (!class_exists('Rasuvaeff\PinnedHttpClient\PinnedClient') || !class_exists('GuzzleHttp\Psr7\HttpFactory')) {
    echo "This example requires the pinned client. Run:\n  composer require rasuvaeff/pinned-http-client\n";
    exit(0);
}

use GuzzleHttp\Psr7\HttpFactory;
use Psr\Clock\ClockInterface;
use Rasuvaeff\PinnedHttpClient\PinnedClient;
use Rasuvaeff\Yii3Webhooks\Examples\HttpWebhookDispatcher;
use Rasuvaeff\Yii3Webhooks\HmacSha256Signer;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;

$factory = new HttpFactory();
$clock = new class implements ClockInterface {
    public function now(): \DateTimeImmutable { return new \DateTimeImmutable(); }
};

// The resolver is injected only so that this example needs no network: here every name resolves to a private address,
// as it would for a registrant who points a host name at your internal network. In production leave it out and the
// client resolves through DNS (`gethostbynamel`).
$dispatcher = new HttpWebhookDispatcher(
    httpClient: new PinnedClient(
        timeoutSeconds: 5.0,
        connectTimeoutSeconds: 3.0,
        resolver: static fn (string $host): array => ['10.0.0.5'],
    ),
    requestFactory: $factory,
    streamFactory: $factory,
    signer: new HmacSha256Signer(),
    clock: $clock,
);

$event = WebhookEvent::create(type: 'order.created', payload: json_encode(['orderId' => 42], JSON_THROW_ON_ERROR));
$endpoint = new WebhookEndpoint(url: 'https://partner.example.com/webhook', secret: 'whsec_test_secret');

$delivery = $dispatcher->dispatch($event, $endpoint);
echo "Status: {$delivery->getStatus()->value}, Attempts: {$delivery->getAttempts()}\n";
echo 'Last error: ' . ($delivery->getLastError() ?? 'none') . "\n";
