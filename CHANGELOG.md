# Changelog

## 1.0.1 — 2026-06-30

- Add `/benchmarks` and `/Makefile` to `.gitattributes` export-ignore.

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0 — 2026-06-19

- Initial release: HMAC-SHA256 webhook signing and verification.
- `WebhookEvent`, `WebhookEndpoint`, `WebhookSignature`, `HmacSha256Signer`.
- `WebhookVerifier` with configurable timestamp tolerance.
- Replay protection via `ReplayGuard` and `NonceStorage` interface.
- `WebhookDelivery`, `WebhookDeliveryStatus` enum, `WebhookDeliveryStorage` interface.
- `WebhookRetryPolicy` with configurable max attempts and delay.
- `InMemoryDeliveryStorage` and `InMemoryNonceStorage` for testing.
