# AGENTS.md — yii3-webhooks

Guidance for AI agents working on this package. Read before changing code.

## What this is

`rasuvaeff/yii3-webhooks` provides HMAC-signed webhook infrastructure for Yii3:
outbound signing, inbound verification, replay protection, and retry policy.
Namespace: `Rasuvaeff\Yii3Webhooks`.

Public API:
- `WebhookEvent` — immutable event value object (id, type, payload, occurredAt)
- `WebhookEndpoint` — target URL + secret + optional headers
- `WebhookSignature` — HMAC signature (timestamp + value) with header serialization
- `WebhookSigner` — signing interface
- `HmacSha256Signer` — HMAC-SHA256 implementation; signs `"{t}.{payload}"` with secret
- `WebhookVerifier` — inbound verification: timestamp tolerance + HMAC comparison
- `WebhookDelivery` — delivery attempt record (no secret stored — safe to log)
- `WebhookDeliveryStatus` — enum: `Pending`, `Delivered`, `Failed`
- `WebhookDeliveryStorage` — storage interface
- `InMemoryDeliveryStorage` — test implementation
- `WebhookRetryPolicy` — retry logic (maxAttempts, delaySeconds)
- `WebhookDispatcher` — dispatcher interface
- `NonceStorage` — nonce storage interface
- `InMemoryNonceStorage` — test implementation
- `ReplayGuard` — checks and marks nonces, throws on duplicate

HTTP client is NOT a dependency. `WebhookDispatcher` implementations live in
adapter packages.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Secrets must not leak.** `WebhookDelivery` stores only `endpointUrl`, never
   the secret. Use `#[\SensitiveParameter]` on all secret parameters.
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`composer.lock` is gitignored (library).
`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## Invariants & gotchas

- Canonical message for signing: `"{eventId}.{timestamp}.{payload}"` where
  `eventId` is sent as `X-Webhook-Id` header, and `payload` is the exact raw
  body string. Do not re-encode JSON before verification.
- Signature header format: `t={timestamp},v1={hmac_hex}`.
- Signature comparison MUST use `hash_equals()` — never `===`.
- `WebhookVerifier` returns `bool` — it does NOT throw on invalid signatures.
- `NonceStorage::add()` must be atomic and return false on duplicate nonce;
  `ReplayGuard::accept()` throws `RuntimeException` when storage rejects it.
- `WebhookDelivery::withAttempt(DateTimeImmutable, ?string)` increments attempts and
  sets `lastAttemptAt`; `lastError` is cleared when null is passed.
- `WebhookRetryPolicy::isReadyForRetry()` takes `DateTimeImmutable $now` — caller provides clock.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types, `#[\SensitiveParameter]` on secrets.

- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.

## When you finish

- Update `README.md` (and `examples/` if usage changed); update `CHANGELOG.md`
  when releasing.
- Re-run `composer build`; if the change affects the public API or release
  process, also run `make release-check`. Paste the output.
