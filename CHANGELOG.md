# Changelog

## 2.0.0 — 2026-08-22

A major for three breaking changes, none of which
`roave/backward-compatibility-check` can see: it compares the shape of the PHP
API, and all three are behavioural — the bytes on the wire, the URLs the
constructor accepts, and what `save()` writes. The green BC report on this
release is correct and says nothing about upgrade safety. Manual steps, the
legacy signer for a dual-accept window and the rollout order are in
[`UPGRADE.md`](UPGRADE.md).

- **Breaking (signatures on the wire).** The canonical message signed by
  `HmacSha256Signer` is now length-prefixed:
  `"{strlen(eventId)}.{eventId}.{timestamp}.{strlen(payload)}.{payload}"`.
  `.` is legal inside an event id, so the previous
  `"{eventId}.{timestamp}.{payload}"` parsed into several different
  (eventId, timestamp, payload) triples: an event id such as
  `order.1755600000` produced exactly the bytes an attacker could re-frame as
  event id `order`, a timestamp of `1755600000` and a payload of their
  choosing — same signature, different message, and a different nonce, so the
  replay guard was bypassed too. Signature bytes therefore change, in both
  directions: a receiver on 1.x rejects a 2.0 signature and a receiver on 2.0
  rejects a 1.x one, so no rollout order avoids a delivery gap. Removing it
  takes a window in which the receiver accepts both framings — `UPGRADE.md`
  carries the five-line legacy `WebhookSigner` and the order (receivers accept
  both → sender upgrades → receivers drop the legacy branch). Receivers you do
  not control need a coordinated date instead. The replay guard is unaffected:
  the nonce is the `X-Webhook-Id` header, not the signature.
- **Breaking (endpoint validation).** `WebhookEndpoint` now rejects URLs it
  used to accept: anything that is not http/https with a real host, URLs
  carrying credentials (`https://user:pass@host/`), and hosts that are
  loopback, private, link-local or otherwise reserved IP literals —
  `127.0.0.1`, `10.0.0.5`, `169.254.169.254` (cloud metadata), `[::1]`,
  `localhost`. Deliveries that are meant to stay inside the perimeter opt back
  in with `allowPrivateNetwork: true`. Host names are deliberately not
  resolved; the README's Security section spells out what the dispatcher still
  owes (resolve-and-filter, redirect policy, timeouts). Run your stored
  registrations through the constructor before deploying — `UPGRADE.md` has the
  loop.
- **Breaking (endpoint validation, root label).** A single trailing dot is the
  DNS root label: every resolver reads `localhost.` as `localhost`, but the
  string comparison did not, `inet_pton('localhost.')` fails as well, and the
  host was classified as an unresolvable name and accepted. `https://localhost./hook`
  therefore passed the whole loopback check that `https://localhost/hook` did
  not, and so did `https://127.0.0.1./hook` and `https://sub.localhost./hook`.
  The host is now normalised before every check, which also turns `https://./hook`
  — previously accepted as a host name — into the missing host it always was.
  The normalisation touches the comparison only: an accepted URL is still stored
  exactly as it was passed in, so `https://partner.example.com./hook` is
  accepted and `getUrl()` returns it verbatim. Reported by CodeRabbit on #23.
- `ClaimingDeliveryStorage`: an optional interface extending
  `WebhookDeliveryStorage` for backends that can hand a delivery to exactly one
  worker. `claimReady()` leases the deliveries that are ready for another
  attempt — skipping the ones still waiting out their backoff, so a backlog of
  backing-off deliveries no longer starves the ready ones behind it — and
  `releaseClaim()` gives a lease back early. Ownership is a lease, not a
  status: a claimed delivery stays `Pending` and a worker that dies strands
  nothing. Workers select the path with `instanceof`; existing storages keep
  working unchanged.
- `WebhookRetryPolicy::readyThresholds()`: the backoff rule as data a storage
  backend can push into its own query — attempt count => the latest
  `lastAttemptAt` that is ready now.
- `WebhookDeliveryStorage::save()` no longer writes the status of a delivery
  that already exists, in the contract and in `InMemoryDeliveryStorage`. A
  worker that lost the race and still held a stale `Pending` copy could
  otherwise put a finished delivery back into the queue and deliver the same
  webhook again. `markDelivered()`/`markFailed()` are the only writers of a
  status; a claim writes a lease and leaves the delivery `Pending`. Storages
  implemented outside this package must follow suit — see `UPGRADE.md`.
- `InMemoryDeliveryStorage::markDelivered()`/`markFailed()` are a compare-and-set
  on `Pending`, matching what the database backend has always written as
  `UPDATE … WHERE id = ? AND status = 'pending'`. The in-memory storage used to
  overwrite a terminal status unconditionally, so a worker that lost a race could
  turn a `Failed` delivery into a `Delivered` one — and consumer code debugged
  against this storage behaved differently in production. Marking a delivery that
  is unknown or already finished is now a silent no-op everywhere.
- `WebhookRetryPolicy::nextDelaySeconds()` applies the cap before the int cast.
  An exponential policy with a large `maxAttempts` overflowed `PHP_INT_MAX`
  within a few dozen attempts, and the out-of-range cast (platform-defined,
  typically `PHP_INT_MIN`) made the delay negative — `isReadyForRetry()` then
  built `modify('+-9223372036854775808 seconds')` and took the worker down with
  a `DateMalformedStringException`.
- Docs: what a retry worker that survives a restart still owes — the payload and
  the endpoint secret are deliberately not part of a `WebhookDelivery`, and both
  lookups belong on the batch rather than on each delivery.
- `examples/dispatcher.php` builds Guzzle with `allow_redirects => false` and
  connect/read timeouts, and `examples/claiming_worker.php` shows the two-worker
  polling loop.
- Docs: the endpoint URL is sensitive and a `WebhookDelivery` is **not** safe to
  log as-is, correcting what README, `llms.txt`, `AGENTS.md` and the agent skill
  all said. `WebhookEndpoint` rejects credentials but accepts a query string and
  stores the URL verbatim, so `https://host/hook?token=…` puts a receiver's
  credential in every delivery row and every log line that echoes one. Redact
  query and fragment before logging; the recipe is in the Security section.
  Nothing in this package logs a URL — the fix is a corrected claim, not new
  API. Reported by CodeRabbit on #23.
- Docs: the multi-worker recipe no longer falls back to `findPending()`. The
  `instanceof ClaimingDeliveryStorage ? claimReady() : findPending()` shape was
  shown under a heading about running several workers, where the fallback branch
  hands the same batch to all of them and delivers every webhook once per
  worker. README (both languages), `llms.txt`, the interface docblocks and
  `examples/claiming_worker.php` now throw instead, and say that a storage which
  cannot claim means one worker. Reported by CodeRabbit on #23.
- Docs: `UPGRADE.md`, and the signature-format migration it describes, in
  README (both languages) and the agent skill's safety rules — a receiver that
  cannot verify the new framing is now a stated precondition of upgrading the
  sender. Reported by CodeRabbit on #23.

## 1.2.1 — 2026-07-25

- Hygiene: anchor the timestamp digit pattern in
  `WebhookSignature::fromHeaderValue()` with `\z` instead of `$` (PCRE `$`
  matches before a trailing `\n`). Not observable through public API — the
  header parser already `trim()`s each field before the regex runs.

## 1.2.0 — 2026-07-25

- `WebhookEvent::create()` accepts an optional `id`. The event id travels to
  the receiver in `X-Webhook-Id` and is what they deduplicate on, so a webhook
  mirroring a domain event should carry that event's identifier — otherwise a
  republish arrives under a new id and the receiver's retry handling is
  defeated. Omitted, the id stays 32 random hex characters.
- `WebhookDelivery::create()` accepts an optional `id` as well, for
  applications that generate delivery record ids themselves. Keep it within 32
  characters — the column width in `rasuvaeff/yii3-webhooks-db`.
- No id generator interface here, unlike `rasuvaeff/yii3-outbox`:
  `WebhookDispatcher` is an interface and the application owns the object that
  would hold one. The README shows the wrapper factory for a project-wide id
  scheme.

## 1.1.0 — 2026-07-25

- Ship an AI agent skill (`resources/skills/rasuvaeff-yii3-webhooks/SKILL.md` +
  `extra.skills` in composer.json): projects using the `llm/skills` Composer
  plugin get the skill synced into `.agents/skills/` automatically on install.
- Bump `rasuvaeff/property-testing` dev dependency to `^2.6`.
- Make property-test generator methods `public static` (private ones are
  removed by rector's `RemoveUnusedPrivateMethodRector` — they are called via
  reflection only).

## 1.0.2 — 2026-06-30

- Add `/benchmarks` and `/Makefile` to `.gitattributes` export-ignore.
- Pin `testo/bridge-infection` to `0.1.6`: 0.1.7/0.1.8 (2026-06-29) misclassify failing tests as passed under mutants, producing false escapes in mutation testing.

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.1 — 2026-06-27

- Migrate test suite from PHPUnit to Testo. Internal change, no public API impact.

## 1.0.0 — 2026-06-19

- Initial release: HMAC-SHA256 webhook signing and verification.
- `WebhookEvent`, `WebhookEndpoint`, `WebhookSignature`, `HmacSha256Signer`.
- `WebhookVerifier` with configurable timestamp tolerance.
- Replay protection via `ReplayGuard` and `NonceStorage` interface.
- `WebhookDelivery`, `WebhookDeliveryStatus` enum, `WebhookDeliveryStorage` interface.
- `WebhookRetryPolicy` with configurable max attempts and delay.
- `InMemoryDeliveryStorage` and `InMemoryNonceStorage` for testing.

