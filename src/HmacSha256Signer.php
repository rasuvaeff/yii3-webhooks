<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks;

/**
 * Signs the canonical message `len(eventId).eventId.timestamp.len(payload).payload`
 * with HMAC-SHA256.
 *
 * The length prefixes are what makes the message canonical. Without them, `.`
 * is both the separator and a legal character inside an event id, so one signed
 * string parses into several different (eventId, timestamp, payload) triples:
 * an event id `order.1755600000` signed at `1755600300` produces exactly the
 * bytes that an attacker can re-frame as event id `order`, timestamp
 * `1755600000` and a payload of their choosing — same signature, different
 * message, and a different replay-guard nonce. With the prefixes, every parse
 * of the canonical string yields one triple.
 *
 * @api
 */
final readonly class HmacSha256Signer implements WebhookSigner
{
    #[\Override]
    public function sign(
        string $payload,
        #[\SensitiveParameter]
        string $secret,
        int $timestamp,
        string $eventId,
    ): WebhookSignature {
        $canonical = \strlen($eventId) . '.' . $eventId . '.' . $timestamp . '.' . \strlen($payload) . '.' . $payload;
        $value = hash_hmac('sha256', $canonical, $secret);

        return new WebhookSignature(
            timestamp: $timestamp,
            value: $value,
        );
    }
}
