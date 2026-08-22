# Upgrade guide

Only versions that need manual steps are listed. Anything not mentioned here is
a drop-in update.

## 1.x → 2.0

Three of the breaking changes need a decision from you before you deploy:
the signature on the wire, the endpoint URLs you already have stored, and any
`WebhookDeliveryStorage` you implemented yourself.

`roave/backward-compatibility-check` reports this release as clean. That is
correct and not reassuring: it compares the shape of the PHP API, and all three
breaks are behavioural. Read this file rather than the BC report.

### 1. The signed message changed — receivers break unless you stage it

`HmacSha256Signer` now signs

```
"{strlen(eventId)}.{eventId}.{timestamp}.{strlen(payload)}.{payload}"
```

where 1.x signed `"{eventId}.{timestamp}.{payload}"`. The reason is in
`CHANGELOG.md`: `.` is legal inside an event id, so the old string parsed into
several different (eventId, timestamp, payload) triples and an intercepted
delivery could be re-framed around the same signature.

Every byte of the signature changes. A receiver running 1.x rejects a signature
produced by 2.0, and a receiver running 2.0 rejects a signature produced by 1.x
— so there is **no rollout order that avoids a gap**. Upgrading the receivers
first does not shrink the outage, it moves it. What removes it is a window in
which the receiver accepts both framings.

#### If you control both sides

1. **Receivers accept both.** Deploy a legacy signer next to the current one and
   accept when either verifier says yes. The legacy signer is the 1.x
   implementation, five lines:

   ```php
   use Rasuvaeff\Yii3Webhooks\WebhookSignature;
   use Rasuvaeff\Yii3Webhooks\WebhookSigner;

   /**
    * The message rasuvaeff/yii3-webhooks 1.x signed. Keep it only for as long
    * as senders on 1.x are still delivering, then delete it — it is the
    * framing this release exists to retire.
    */
   final readonly class LegacyV1Signer implements WebhookSigner
   {
       #[\Override]
       public function sign(
           string $payload,
           #[\SensitiveParameter]
           string $secret,
           int $timestamp,
           string $eventId,
       ): WebhookSignature {
           return new WebhookSignature(
               timestamp: $timestamp,
               value: hash_hmac('sha256', $eventId . '.' . $timestamp . '.' . $payload, $secret),
           );
       }
   }
   ```

   ```php
   $current = new WebhookVerifier(new HmacSha256Signer(), $clock, toleranceSeconds: 300);
   $legacy = new WebhookVerifier(new LegacyV1Signer(), $clock, toleranceSeconds: 300);

   $valid = $current->verify($rawBody, $secret, $signature, $eventId)
       || $legacy->verify($rawBody, $secret, $signature, $eventId);

   if (!$valid) {
       // reject the request
   }

   $replayGuard->accept($eventId); // unchanged — see below
   ```

   Both verifiers compare with `hash_equals()`, so accepting two framings costs
   one extra constant-time comparison and leaks nothing.

2. **Upgrade the sender to 2.0** once every receiver is on step 1.

3. **Delete `LegacyV1Signer`** from the receivers. Do not leave it in: while it
   is wired up, the vulnerable framing is still accepted, which is the whole
   point of the release. Anything older than the timestamp tolerance is already
   rejected, so you can drop it as soon as the sender has been on 2.0 for longer
   than one tolerance window plus your retry horizon.

The replay guard is unaffected: the nonce is the `X-Webhook-Id` header, not the
signature, so a dual-accept window does not widen the replay window.

#### If you do not control the receivers

There is no staging available to you — a third party's verifier is theirs to
change. Announce the change with a date, publish the new canonical message (it
is in `README.md` and in `resources/skills/.../SKILL.md`), and coordinate the
switch. Deploying 2.0 without that turns every delivery into a signature
rejection and burns through the retry budget of every endpoint you have.

If you cannot coordinate, staying on 1.2.1 is a supported choice — but you keep
the re-framing weakness, so restrict event ids to a character set without `.`
in the meantime.

### 2. `WebhookEndpoint` rejects URLs it used to accept

The constructor now refuses:

| URL | Why | What to do |
|---|---|---|
| `https://user:pass@host/hook` | credentials are a secret and the delivery row stores the URL verbatim | move the credential into `headers` |
| `http://127.0.0.1/hook`, `http://10.0.0.5/hook`, `http://[::1]/hook`, `http://localhost/hook` | loopback / private / link-local / reserved literals are SSRF targets | pass `allowPrivateNetwork: true` for endpoints that are meant to stay inside the perimeter |
| `http://169.254.169.254/...` | cloud metadata | as above, and think twice |
| `http://localhost./hook`, `http://127.0.0.1./hook` | the trailing dot is the DNS root label; resolvers strip it, so these are the same hosts as above | as above |
| `https://./hook` | a host that is only the root label is no host | fix the URL |
| `ftp://host/hook`, `http://exa mple.com/hook` | not http/https, or not a host at all | fix the URL |

Ordinary public hosts, including ones written with a root label
(`https://partner.example.com./hook`), are accepted unchanged and stored
verbatim.

**Check your stored endpoints before deploying.** A registration that the old
version accepted now throws `InvalidArgumentException` at construction time,
which in a delivery worker means the batch dies rather than the delivery. Run
your endpoint table through the constructor first:

```php
foreach ($repository->all() as $row) {
    try {
        new WebhookEndpoint(url: $row->url, secret: $row->secret);
    } catch (InvalidArgumentException $e) {
        // report it: this registration needs allowPrivateNetwork, a fixed URL,
        // or to be disabled before the deploy
    }
}
```

Host names are still not resolved — the dispatcher owes resolve-and-filter,
redirect policy and timeouts. See the Security section of `README.md`.

### 3. `save()` no longer writes the status of an existing delivery

If you implement `WebhookDeliveryStorage` yourself, `save()` must now update
only the attempt state (attempts, last attempt, last error) of a delivery that
already exists, and leave its status alone. In SQL that is an `UPDATE` whose
`SET` list no longer mentions the status column.

`markDelivered()` and `markFailed()` must be a compare-and-set on `Pending`
(`UPDATE … WHERE id = ? AND status = 'pending'`), and marking a delivery that is
unknown or already finished must be a silent no-op. `InMemoryDeliveryStorage`
behaves this way as of this release, so a consumer debugged against it now
behaves the same in production.

If more than one worker polls your storage, implement
`ClaimingDeliveryStorage` as well. `claimReady()` writes a lease and nothing
else — a claimed delivery stays `Pending`, and the two terminal methods above
remain the only writers of the status.

`rasuvaeff/yii3-webhooks-db` ships the matching changes in its own major; upgrade
the two together.

### 4. The endpoint URL is sensitive — check your logging

Nothing in the API changed here, but the guidance did, and it was wrong before:
`WebhookDelivery` was described as safe to log as-is because it carries no
secret. It carries `endpointUrl`, and `https://host/hook?token=…` is a common
and legitimate way for a receiver to authenticate itself. The URL is stored
verbatim, so that token is in your delivery table and in anything that logs a
delivery.

Redact the query and the fragment wherever a delivery or an endpoint reaches a
log, a metric label, an error report or a support screen:

```php
$parts = parse_url($delivery->getEndpointUrl());
$safe = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . ($parts['path'] ?? '');
```
