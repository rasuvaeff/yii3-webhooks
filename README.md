# rasuvaeff/yii3-webhooks

[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-webhooks/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-webhooks)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-webhooks/downloads)](https://packagist.org/packages/rasuvaeff/yii3-webhooks)
[![Build](https://github.com/rasuvaeff/yii3-webhooks/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-webhooks/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-webhooks/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-webhooks/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-webhooks/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-webhooks)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-webhooks/php)](https://packagist.org/packages/rasuvaeff/yii3-webhooks)
[![License](https://poser.pugx.org/rasuvaeff/yii3-webhooks/license)](https://packagist.org/packages/rasuvaeff/yii3-webhooks)
[Русская версия](README.ru.md)

HMAC-signed webhook infrastructure for Yii3: outbound signing, inbound
verification, replay protection, and delivery retry policy. It signs the exact
payload bytes you send or receive; no hard HTTP client dependency — bring your
own dispatcher.

> Using an AI coding assistant? [llms.txt](llms.txt) has a compact API reference you can use.
> Projects using the [llm/skills](https://github.com/roxblnfk/skills) Composer plugin also get this package's agent skill synced into `.agents/skills/` automatically on install.

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

### Event ids

The event id travels to the receiver in `X-Webhook-Id` and is what they
deduplicate on. When a webhook mirrors a domain event that already has an
identifier, pass it — otherwise a republish of the same domain event arrives
under a new id and the receiver's retry handling is defeated:

```php
$event = WebhookEvent::create(
    type: 'order.created',
    payload: $json,
    id: $domainEvent->getId(),
);
```

Omitted, the id is 32 random hex characters, unchanged from previous versions.

Unlike [rasuvaeff/yii3-outbox](https://github.com/rasuvaeff/yii3-outbox), this
package binds no id generator: `WebhookDispatcher` is an interface and the
application owns the object that would hold one. For a house-wide id scheme,
wrap the factory:

```php
final readonly class EventFactory
{
    public function __construct(private ClockInterface $clock) {}

    public function create(string $type, string $payload): WebhookEvent
    {
        return WebhookEvent::create(
            type: $type,
            payload: $payload,
            occurredAt: $this->clock->now(),
            id: Uuid::v7()->toRfc4122(), // symfony/uid or ramsey/uuid, your call
        );
    }
}
```

`WebhookDelivery::create()` takes `id` too, for applications that generate
delivery record ids themselves. Keep those within 32 characters — that is the
column width in `rasuvaeff/yii3-webhooks-db`.

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

### Delivering with more than one worker

`findPending()` hands the same deliveries to every worker that asks, and it knows nothing about backoff — so two workers deliver the same event twice, and a backlog of deliveries waiting out their backoff fills every batch while ready ones behind them starve. A storage implementing `ClaimingDeliveryStorage` solves both:

```php
use Rasuvaeff\Yii3Webhooks\ClaimingDeliveryStorage;

$now = $clock->now();

$batch = $storage instanceof ClaimingDeliveryStorage
    ? $storage->claimReady(
        now: $now,
        readyThresholds: $policy->readyThresholds($now),
        maxAttempts: $policy->getMaxAttempts(),
        leaseSeconds: 300,
        limit: 100,
    )
    : $storage->findPending();

foreach ($batch as $delivery) {
    // ... deliver, then move it out of the claim:
    // $storage->markDelivered($delivery->withAttempt($now));
    // $storage->markFailed($delivery->withAttempt($now, error: $error));
    // or, for a retryable failure:
    //   $storage->save($delivery->withAttempt($now, error: $error));
    //   $storage->releaseClaim($delivery);
}
```

`leaseSeconds` must outlive the slowest delivery attempt: a lease that expires while its worker is still delivering lets a second worker claim the same delivery. Every claimed delivery must be marked or released, or it waits out the whole lease before anyone sees it again.

## API reference

### WebhookEvent

| Method | Description |
|---|---|
| `create(type, payload, occurredAt?, id?)` | Factory; `id` = the domain event's id, omitted → 32-char hex |
| `getId()` | 32-char hex ID |
| `getType()` | Event type string |
| `getPayload()` | Raw payload bytes to sign and deliver |
| `getOccurredAt()` | `DateTimeImmutable` |

### WebhookEndpoint

| Method | Description |
|---|---|
| `__construct(url, secret, headers?, allowPrivateNetwork?)` | http/https scheme, a host, no credentials in the URL, non-empty secret |
| `getUrl()` | Endpoint URL |
| `getSecret()` | Shared secret (not stored in delivery) |
| `getHeaders()` | Additional request headers |
| `allowsPrivateNetwork()` | Whether this endpoint may point inside the perimeter |

A URL whose host is a loopback, private, link-local or otherwise reserved IP literal (`127.0.0.1`, `10.0.0.5`, `169.254.169.254`, `[::1]`, `localhost`) is rejected — see [Security](#security). Pass `allowPrivateNetwork: true` for endpoints that are meant to stay inside the perimeter. Credentials in the URL (`https://user:pass@host/`) are rejected outright: they are a secret, and the delivery record stores the URL verbatim. Use `headers` for authentication.

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

Signs `"{len(eventId)}.{eventId}.{timestamp}.{len(payload)}.{payload}"` with the secret using HMAC-SHA256. `payload` is the exact HTTP body string, not a re-encoded JSON value.

The length prefixes are what makes the message canonical: `.` is legal inside an event id, so without them one signed string parses into several different (eventId, timestamp, payload) triples and an intercepted delivery can be re-framed around the same signature.

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
| `readyThresholds(now)` | The same rule as data a storage can query: attempt count => the latest `lastAttemptAt` that is ready now |

### WebhookDelivery

| Method | Description |
|---|---|
| `create(event, endpoint, createdAt?, id?)` | Factory; stores URL only (no secret). Keep `id` ≤ 32 chars (DB column width) |
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
| `save(delivery)` | Stores a new delivery, or updates the attempt state of one already stored — never its status |
| `findPending(limit)` | Returns pending deliveries, ready or not, claimed or not |
| `markDelivered(delivery)` | Marks a delivery as delivered, if it is still pending |
| `markFailed(delivery)` | Marks a delivery as failed, if it is still pending |
| `getById(id)` | Loads a delivery by ID |

The status of a delivery that already exists is deliberately not written by `save()`: a worker holding a stale copy would otherwise put a finished delivery back into `Pending` and deliver the same webhook again.

### ClaimingDeliveryStorage

Optional interface, extending `WebhookDeliveryStorage`, for backends that can hand a delivery to exactly one worker. `rasuvaeff/yii3-webhooks-db` implements it; `InMemoryDeliveryStorage` does too.

| Method | Description |
|---|---|
| `claimReady(now, readyThresholds, maxAttempts, leaseSeconds?, limit?)` | Leases the deliveries that are ready for another attempt, skipping the ones still backing off |
| `releaseClaim(delivery)` | Gives a lease back early; `true` when one was cleared |

Ownership is a lease, not a status: a claimed delivery stays `Pending`, and a worker that dies never strands it — the lease simply expires. Exhausted deliveries (`attempts >= maxAttempts`) are handed out too, because only the caller can mark them `Failed`.

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
| `save(delivery)` | Stores a delivery record; keeps the stored status of one already there |
| `findPending(limit)` | Returns pending deliveries |
| `claimReady(now, readyThresholds, maxAttempts, leaseSeconds?, limit?)` | Leases ready deliveries — same contract as the DB backend |
| `releaseClaim(delivery)` | Gives a lease back early |
| `markDelivered(delivery)` | Sets status to `Delivered`, if the stored delivery is still pending |
| `markFailed(delivery)` | Sets status to `Failed`, if the stored delivery is still pending |
| `getById(id)` | Loads a delivery by ID |
| `clear()` | Removes all records and leases |

## Security

- Signature comparison uses `hash_equals()` — safe against timing attacks.
- The canonical message is length-prefixed, so it parses into exactly one
  (eventId, timestamp, payload) triple. A custom `WebhookSigner` must keep that
  property: concatenating variable-length components with a separator that can
  occur inside them lets an intercepted delivery be re-framed around the same
  signature, with a different payload and a different replay-guard nonce.
- `WebhookDelivery` stores only the endpoint URL, never the secret. Credentials
  in the URL are rejected for that reason — the URL is stored verbatim on every
  delivery row; use `headers` for authentication.
- All secret parameters are marked `#[\SensitiveParameter]` — they do not appear in stack traces.
- Always validate timestamps (tolerance) to prevent replay of old signatures.
- Use `ReplayGuard` with a persistent `NonceStorage` in production; storage
  implementations must reject duplicates atomically.

### SSRF: the endpoint URL is untrusted input

Wherever users register their own webhook endpoints, the destination URL is
attacker-controlled and your delivery worker runs inside the trusted network.
`WebhookEndpoint` therefore rejects credentials in the URL and hosts that are
loopback, private, link-local or otherwise reserved IP literals — including
`169.254.169.254`, the cloud metadata address. `allowPrivateNetwork: true` opts
a single endpoint back in.

That check alone is not an anti-SSRF policy, and the constructor deliberately
does not resolve host names. The dispatcher owns the rest:

- **Resolve and filter at delivery time.** A name that resolves into a private
  range is an SSRF target whatever the URL looks like, and DNS can change
  between registration and delivery.
- **Refuse redirects, or re-check every hop.** A URL that passed every check can
  redirect into the internal network. Guzzle follows up to 5 redirects by
  default: `new Client(['allow_redirects' => false])`.
- **Set timeouts.** Guzzle's default `timeout` is `0` — a receiver that accepts
  the connection and never answers parks the worker forever.
- **Do not hand response bodies back to the registrant.** `lastError` is returned
  by delivery-status APIs; an internal response echoed there is the payoff of
  the attack.

See [examples/dispatcher.php](examples/dispatcher.php) for the client defaults.

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
