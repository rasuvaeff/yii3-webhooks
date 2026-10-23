# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `signing.php` | Sign outbound event, build signature header | No |
| `verification.php` | Verify inbound webhook, replay protection | No |
| `delivery_tracking.php` | Track deliveries, retry policy | No |
| `claiming_worker.php` | Lease deliveries to one worker, skip backing-off ones | No |
| `dispatcher.php` | SSRF-safe WebhookDispatcher over `rasuvaeff/pinned-http-client` (PSR-18); a private host is refused as a failed attempt | No |
