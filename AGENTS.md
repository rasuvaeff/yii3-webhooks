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
- `HmacSha256Signer` — HMAC-SHA256 implementation; signs the length-prefixed
  canonical message (see Invariants)
- `WebhookVerifier` — inbound verification: timestamp tolerance + HMAC comparison
- `WebhookDelivery` — delivery attempt record (no secret stored — safe to log)
- `WebhookDeliveryStatus` — enum: `Pending`, `Delivered`, `Failed`
- `WebhookDeliveryStorage` — storage interface
- `ClaimingDeliveryStorage` — optional storage interface: lease-based claiming
- `InMemoryDeliveryStorage` — test implementation (implements both)
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

- **Event id belongs to the domain, not to this package.** It leaves in
  `X-Webhook-Id` and is the receiver's deduplication key, so
  `WebhookEvent::create(id: ...)` should carry the domain event's identifier;
  omitted, it falls back to 32 random hex characters. There is deliberately no
  id-generator interface (unlike `yii3-outbox`): `WebhookDispatcher` is an
  interface, so no object in this package could hold one — the application owns
  id policy and the README shows the wrapper-factory recipe.
- **Delivery ids must stay within 32 characters** — the `id` column width in
  `yii3-webhooks-db`. Nothing derives meaning from the id's shape (the storage
  uses it only as an equality condition), so `create(id: ...)` is pure
  convenience, but the ceiling is real.
- Canonical message for signing:
  `"{strlen(eventId)}.{eventId}.{timestamp}.{strlen(payload)}.{payload}"`, where
  `eventId` is sent as the `X-Webhook-Id` header and `payload` is the exact raw
  body string. Do not re-encode JSON before verification. **The length prefixes
  are load-bearing**: `.` is legal inside an event id, so without them one signed
  string parses into several (eventId, timestamp, payload) triples and an
  intercepted delivery can be re-framed around the same signature with a payload
  of the attacker's choosing (and a different replay-guard nonce). Any change to
  the canonical message breaks every deployed receiver — treat it as such.
- **Storage contract.** `save()` must never write the status of a delivery that
  already exists (a stale copy would resurrect a finished delivery); status
  belongs to `markDelivered()`/`markFailed()`/the claim. With more than one
  worker the storage must implement `ClaimingDeliveryStorage`: ownership is a
  lease (the delivery stays `Pending` and becomes claimable again when the lease
  expires), and `claimReady()` must also hand out deliveries with
  `attempts >= maxAttempts` — nothing else can terminate them.
- **`WebhookEndpoint` treats the URL as attacker-controlled**: http/https only,
  a host that is a host name or IP literal, no credentials, and no loopback /
  private / link-local / reserved IP literal unless `allowPrivateNetwork: true`.
  It deliberately does not resolve host names — resolving guards, redirect
  policy and timeouts belong to the dispatcher (README Security section).
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
