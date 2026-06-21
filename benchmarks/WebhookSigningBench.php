<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Benchmarks;

use Rasuvaeff\Yii3Webhooks\HmacSha256Signer;
use Rasuvaeff\Yii3Webhooks\WebhookSignature;
use Testo\Bench;

/**
 * Compares HmacSha256Signer signing a small payload vs a large payload,
 * measuring how payload size affects HMAC-SHA256 throughput.
 */
final class WebhookSigningBench
{
    private const string SECRET = 'whsec_test_secret_key_32bytes_xx';
    private const string EVENT_ID = 'evt_01j9z0000000000000000000';
    private const int TIMESTAMP = 1_700_000_000;
    private const string SMALL_PAYLOAD = '{"event":"user.created","id":1}';
    private const string LARGE_PAYLOAD = '{"event":"order.updated","id":99999,"items":[{"sku":"ITEM-001","qty":3,"price":1250},{"sku":"ITEM-002","qty":1,"price":5000},{"sku":"ITEM-003","qty":2,"price":750}],"customer":{"id":42,"email":"user@example.com","name":"Jane Doe"},"total":14750,"currency":"USD","metadata":{"source":"checkout","session":"sess_abc123"}}';

    #[Bench(
        callables: [
            'large-payload' => [self::class, 'signLargePayload'],
        ],
        calls: 1_000,
        iterations: 10,
    )]
    public static function signSmallPayload(): WebhookSignature
    {
        return (new HmacSha256Signer())->sign(
            payload: self::SMALL_PAYLOAD,
            secret: self::SECRET,
            timestamp: self::TIMESTAMP,
            eventId: self::EVENT_ID,
        );
    }

    public static function signLargePayload(): WebhookSignature
    {
        return (new HmacSha256Signer())->sign(
            payload: self::LARGE_PAYLOAD,
            secret: self::SECRET,
            timestamp: self::TIMESTAMP,
            eventId: self::EVENT_ID,
        );
    }
}
