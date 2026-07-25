---
name: rasuvaeff-yii3-webhooks
description: >-
  HMAC-signed webhook infrastructure for Yii3 with rasuvaeff/yii3-webhooks —
  HmacSha256Signer, WebhookVerifier, WebhookSignature, ReplayGuard,
  WebhookDelivery, WebhookRetryPolicy. Use when writing, reviewing or debugging
  webhook signing, inbound signature verification, replay protection or
  delivery retries in a project that has this package installed, or when a
  webhook endpoint accepts requests without verifying `X-Webhook-Signature`.
---

# rasuvaeff/yii3-webhooks

Outbound HMAC-SHA256 signing, inbound verification, replay protection and
retry policy for webhooks. No HTTP client dependency — you implement
`WebhookDispatcher`. Namespace `Rasuvaeff\Yii3Webhooks\`.

## Safety rules — verify these on every change

1. **Never hand-roll signing or comparison.** The canonical signed message is
   `"{eventId}.{timestamp}.{payload}"` where `payload` is the exact raw HTTP
   body string — do not re-encode JSON before verifying. Comparison must be
   constant-time; the package uses `hash_equals()` internally.

   ```php
   $verifier->verify(payload: $rawBody, secret: $secret, signature: $sig, eventId: $id); // correct
   hash_hmac('sha256', $payload, $secret) === $received;                                 // wrong: timing leak, wrong message
   ```

2. **Replay protection is mandatory on inbound endpoints.** A valid signature
   alone is replayable within the timestamp tolerance. Use the event ID from
   the `X-Webhook-Id` header as the nonce: `ReplayGuard::accept($eventId)`
   throws `RuntimeException` on a duplicate; `NonceStorage::add()` must be
   atomic and return `false` on duplicates.

3. **Secrets never go in URLs or logs.** Transport the signature via the
   `X-Webhook-Signature: t={ts},v1={hex}` header and the secret via
   config/DI. `WebhookDelivery` deliberately stores only `endpointUrl` —
   never add the secret to it; it is safe to log as-is.

4. **`WebhookVerifier::verify()` returns `bool` — it does not throw.**
   Reject the request yourself on `false`. Timestamp tolerance
   (`toleranceSeconds`, default in examples 300) bounds the replay window.

5. **Pass the domain event's id to `WebhookEvent::create(id: ...)`.** That id
   leaves in `X-Webhook-Id` and is the receiver's deduplication key — if the
   package generates one, a republish of the same domain event arrives under a
   new id and the receiver cannot tell it is a duplicate. Omitted, the id is 32
   random hex characters. `WebhookDelivery::create(id: ...)` exists too; keep
   it within 32 characters, the column width in `yii3-webhooks-db`.

6. **Finalize exhausted deliveries.** When `WebhookRetryPolicy::shouldRetry()`
   is `false`, call `$storage->markFailed($delivery)` — otherwise the delivery
   stays `Pending` forever.

## Canonical usage

```php
use Rasuvaeff\Yii3Webhooks\{HmacSha256Signer, WebhookSignature, WebhookVerifier};

// Outbound: sign, then send X-Webhook-Id + X-Webhook-Signature headers
$signer = new HmacSha256Signer();
$signature = $signer->sign(
    payload: $event->getPayload(),
    secret: $endpoint->getSecret(),
    timestamp: $clock->now()->getTimestamp(),
    eventId: $event->getId(),
);
$header = $signature->toHeaderValue(); // "t=1717228800,v1=<hex>"

// Inbound: verify raw body, then guard against replay
$verifier = new WebhookVerifier(signer: $signer, clock: $clock, toleranceSeconds: 300);
$sig = WebhookSignature::fromHeaderValue($request->getHeaderLine('X-Webhook-Signature'));
$eventId = $request->getHeaderLine('X-Webhook-Id');
$valid = $verifier->verify(payload: $body, secret: $secret, signature: $sig, eventId: $eventId);
```

## Full API

The complete reference — `WebhookEvent`/`WebhookEndpoint` value objects,
delivery tracking, `WebhookRetryPolicy::fixed()`/`exponential()`, storage
interfaces — ships with the package: read
`vendor/rasuvaeff/yii3-webhooks/llms.txt` before guessing a method name.
