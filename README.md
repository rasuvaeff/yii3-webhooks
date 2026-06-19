# rasuvaeff/yii3-webhooks

[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-webhooks/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-webhooks)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-webhooks/downloads)](https://packagist.org/packages/rasuvaeff/yii3-webhooks)
[![Build](https://github.com/rasuvaeff/yii3-webhooks/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-webhooks/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-webhooks/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-webhooks/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-webhooks/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-webhooks)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-webhooks/php)](https://packagist.org/packages/rasuvaeff/yii3-webhooks)
[![License](https://poser.pugx.org/rasuvaeff/yii3-webhooks/license)](https://packagist.org/packages/rasuvaeff/yii3-webhooks)

HMAC-signed webhook infrastructure for Yii3: outbound signing, inbound
verification, replay protection, and delivery retry policy. It signs the exact
payload bytes you send or receive; no hard HTTP client dependency — bring your
own dispatcher.

> Using an AI coding assistant? [llms.txt](llms.txt) has a compact API reference you can use.

## Requirements

- PHP 8.3+
- `psr/clock` ^1.0

## Installation

```bash
composer require rasuvaeff/yii3-webhooks
```

## Usage

### Signing an outbound webhook

```php
use Rasuvaeff\Yii3Webhooks\HmacSha256Signer;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;

$signer = new HmacSha256Signer();
$endpoint = new WebhookEndpoint(
    url: 'https://partner.example.com/webhook',
    secret: 'whsec_...',
);

$event = WebhookEvent::create(
    type: 'order.created',
    payload: json_encode(['orderId' => 42]),
);

$timestamp = $clock->now()->getTimestamp();
$signature = $signer->sign(
    payload: $event->getPayload(),
    secret: $endpoint->getSecret(),
    timestamp: $timestamp,
    eventId: $event->getId(),
);

// Add to outgoing request:
// X-Webhook-Id: <event_id>
// X-Webhook-Signature: t=1717228800,v1=<hmac_hex>
$header = $signature->toHeaderValue();
```

### Verifying an inbound webhook

```php
use Rasuvaeff\Yii3Webhooks\HmacSha256Signer;
use Rasuvaeff\Yii3Webhooks\WebhookSignature;
use Rasuvaeff\Yii3Webhooks\WebhookVerifier;

$verifier = new WebhookVerifier(
    signer: new HmacSha256Signer(),
    clock: $clock,
    toleranceSeconds: 300,
);

$signature = WebhookSignature::fromHeaderValue(
    $request->getHeaderLine('X-Webhook-Signature'),
);

$eventId = $request->getHeaderLine('X-Webhook-Id');

$valid = $verifier->verify(
    payload: (string) $request->getBody(),
    secret: 'whsec_...',
    signature: $signature,
    eventId: $eventId,
);
```

### Replay protection

Use the event ID (from the `X-Webhook-Id` header) as the nonce — it uniquely
identifies the delivery and allows replay detection independently of signature
verification.

```php
use Rasuvaeff\Yii3Webhooks\InMemoryNonceStorage;
use Rasuvaeff\Yii3Webhooks\ReplayGuard;

$guard = new ReplayGuard(new InMemoryNonceStorage());

// $eventId = $request->getHeaderLine('X-Webhook-Id');
if ($valid) {
    $guard->accept($eventId); // throws RuntimeException if already seen
    // process the webhook...
}
```

### Tracking deliveries

```php
use Rasuvaeff\Yii3Webhooks\InMemoryDeliveryStorage;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookRetryPolicy;

$storage = new InMemoryDeliveryStorage();
$policy = WebhookRetryPolicy::fixed(maxAttempts: 3, delaySeconds: 60);
// or: WebhookRetryPolicy::exponential(maxAttempts: 5, baseSeconds: 10, cap: 3600)

$delivery = WebhookDelivery::create(event: $event, endpoint: $endpoint);
$storage->save($delivery);

// After attempt:
$delivery = $delivery->withAttempt($clock->now(), error: 'Connection refused');
$storage->save($delivery);

if ($policy->isReadyForRetry($delivery, $clock->now())) {
    // retry...
}
```

## API reference

### WebhookEvent

| Method | Description |
|---|---|
| `create(type, payload, occurredAt?)` | Factory with auto-generated ID |
| `getId()` | 32-char hex ID |
| `getType()` | Event type string |
| `getPayload()` | Raw payload bytes to sign and deliver |
| `getOccurredAt()` | `DateTimeImmutable` |

### WebhookEndpoint

| Method | Description |
|---|---|
| `__construct(url, secret, headers?)` | URL must use http/https; secret non-empty |
| `getUrl()` | Endpoint URL |
| `getSecret()` | Shared secret (not stored in delivery) |
| `getHeaders()` | Additional request headers |

### WebhookSignature

| Method | Description |
|---|---|
| `__construct(timestamp, value)` | Positive timestamp, non-empty value |
| `fromHeaderValue(header)` | Parse `t=...,v1=...` format |
| `toHeaderValue()` | Serialize to `t=...,v1=...` format |
| `getTimestamp()` | Unix timestamp |
| `getValue()` | HMAC hex string |

### WebhookSigner

Interface for outbound signature implementations. Custom signers must sign the exact payload bytes and return a `WebhookSignature`.

| Method | Description |
|---|---|
| `sign(payload, secret, timestamp, eventId)` | Returns `WebhookSignature` |

### HmacSha256Signer

Signs `"{eventId}.{timestamp}.{payload}"` with the secret using HMAC-SHA256. `payload` is the exact HTTP body string, not a re-encoded JSON value.

| Method | Description |
|---|---|
| `sign(payload, secret, timestamp, eventId)` | Returns `WebhookSignature` |

### WebhookVerifier

| Method | Description |
|---|---|
| `__construct(signer, clock, toleranceSeconds?)` | Default tolerance: 300s |
| `verify(payload, secret, signature, eventId)` | Returns `bool`; uses `hash_equals` |

### WebhookRetryPolicy

| Method | Description |
|---|---|
| `fixed(maxAttempts?, delaySeconds?)` | Constant delay; default: 3 attempts, 60s |
| `exponential(maxAttempts?, baseSeconds?, cap?, multiplier?)` | Doubling delay; default: 5 attempts, 10s base, 3600s cap |
| `getMaxAttempts()` | Max retry attempts |
| `nextDelaySeconds(attempts)` | Delay before next attempt; `attempts` = current attempt count |
| `shouldRetry(delivery)` | Returns `true` when status is Pending and attempts < maxAttempts |
| `isReadyForRetry(delivery, now)` | Returns `true` when delay has elapsed |

### WebhookDelivery

| Method | Description |
|---|---|
| `create(event, endpoint, createdAt?)` | Factory; stores URL only (no secret) |
| `getId()` | 32-char hex ID |
| `getEventId()` | Source event ID |
| `getEventType()` | Source event type |
| `getEndpointUrl()` | Endpoint URL |
| `getStatus()` | `WebhookDeliveryStatus` enum |
| `getCreatedAt()` | `DateTimeImmutable` creation time |
| `getAttempts()` | Attempt count |
| `getLastAttemptAt()` | `?DateTimeImmutable` |
| `getLastError()` | `?string` |
| `withAttempt(at, error?)` | Returns new instance with incremented attempts |
| `withStatus(status)` | Returns new instance with updated status |

### WebhookDeliveryStorage

Interface for persistence backends. Core ships `InMemoryDeliveryStorage` for tests; use a persistent backend in production.

| Method | Description |
|---|---|
| `save(delivery)` | Stores a delivery attempt record |
| `findPending(limit)` | Returns pending deliveries |
| `markDelivered(delivery)` | Marks a delivery as delivered |
| `markFailed(delivery)` | Marks a delivery as failed |
| `getById(id)` | Loads a delivery by ID |

### ReplayGuard

| Method | Description |
|---|---|
| `__construct(NonceStorage)` | Storage must atomically reject duplicate nonces |
| `isReplayed(nonce)` | Returns `bool` |
| `accept(nonce)` | Marks as seen; throws `RuntimeException` if duplicate |

### WebhookDeliveryStatus

Backed string enum with three cases:

| Case | Value |
|---|---|
| `Pending` | `'pending'` |
| `Delivered` | `'delivered'` |
| `Failed` | `'failed'` |

### WebhookDispatcher

Interface for HTTP transport implementations. The package ships no concrete dispatcher — bring your own (Guzzle, PSR-18, etc.).

| Method | Description |
|---|---|
| `dispatch(event, endpoint)` | Sends signed webhook; returns `WebhookDelivery` |

### NonceStorage

Interface for replay-protection storage backends. Implementations must reject duplicate nonces atomically.

| Method | Description |
|---|---|
| `has(nonce)` | Returns `true` if nonce was already seen |
| `add(nonce)` | Stores nonce; returns `false` if duplicate |

### InMemoryNonceStorage

Test-only `NonceStorage` implementation. Not safe for production use.

| Method | Description |
|---|---|
| `has(nonce)` | Returns `bool` |
| `add(nonce)` | Returns `false` on duplicate |
| `clear()` | Removes all stored nonces |

### InMemoryDeliveryStorage

Test-only `WebhookDeliveryStorage` implementation. Implements `IteratorAggregate` and `Countable` for easy inspection.

| Method | Description |
|---|---|
| `save(delivery)` | Stores a delivery record |
| `findPending(limit)` | Returns pending deliveries |
| `markDelivered(delivery)` | Sets status to `Delivered` |
| `markFailed(delivery)` | Sets status to `Failed` |
| `getById(id)` | Loads a delivery by ID |
| `clear()` | Removes all records |

## Security

- Signature comparison uses `hash_equals()` — safe against timing attacks.
- `WebhookDelivery` stores only the endpoint URL, never the secret.
- All secret parameters are marked `#[\SensitiveParameter]` — they do not appear in stack traces.
- Always validate timestamps (tolerance) to prevent replay of old signatures.
- Use `ReplayGuard` with a persistent `NonceStorage` in production; storage
  implementations must reject duplicates atomically.

## Examples

See [examples/](examples/) for complete usage examples.

## Development

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
