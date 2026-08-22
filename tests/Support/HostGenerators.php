<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests\Support;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;

/**
 * The host catalogue behind the endpoint accept/reject property: one generator
 * per verdict, plus the generator map that decides which of the two a run
 * draws from.
 *
 * It lives outside the test class because a helper only reflection calls has
 * nowhere to hide there — `private static` is what Rector's dead-code set
 * deletes, and an instance helper is what
 * `LocallyCalledStaticMethodToNonStaticRector` demands the moment it is not.
 * As a provider referenced from `#[Property(generators: ...)]` it is neither,
 * and any other test needing a host of a known verdict can reach for it.
 */
final readonly class HostGenerators
{
    /**
     * The generator map of `WebhookEndpointTest::privateHostsRejectedPublicHostsAccepted()`:
     * the flag that picks the verdict under test.
     *
     * @return array<string, ArbitraryInterface>
     */
    public static function verdictFlag(): array
    {
        return ['private' => Gen::bool()];
    }

    /**
     * Hosts a {@see \Rasuvaeff\Yii3Webhooks\WebhookEndpoint} must reject unless
     * `allowPrivateNetwork: true`.
     */
    public static function privateHost(): ArbitraryInterface
    {
        return Gen::frequency([
            // octets without a leading zero: "10.01.0.0" is not an IPv4
            // literal at all, it is a host name, and rightly not blocked
            [3, Gen::regex('127\.(0|[1-9]\d?)\.(0|[1-9]\d?)\.(0|[1-9]\d?)')],
            [3, Gen::regex('10\.(0|[1-9]\d?)\.(0|[1-9]\d?)\.(0|[1-9]\d?)')],
            [2, Gen::regex('192\.168\.(0|[1-9]\d?)\.(0|[1-9]\d?)')],
            [2, Gen::regex('169\.254\.(0|[1-9]\d?)\.(0|[1-9]\d?)')],
            [1, Gen::regex('\[fe80::\d{1,3}\]')],
            // `localhost.` and friends carry the DNS root label — the same
            // names, so the same verdict
            [2, Gen::elements([
                'localhost',
                'api.localhost',
                '[::1]',
                '0.0.0.0',
                'localhost.',
                'api.localhost.',
                '127.0.0.1.',
            ])],
        ]);
    }

    /**
     * Hosts a {@see \Rasuvaeff\Yii3Webhooks\WebhookEndpoint} must accept as
     * they are written.
     */
    public static function publicHost(): ArbitraryInterface
    {
        return Gen::frequency([
            // 8/8 and 9/8 are ordinary public space
            [2, Gen::regex('[89]\.(0|[1-9]\d?)\.(0|[1-9]\d?)\.(0|[1-9]\d?)')],
            [3, Gen::regex('[a-z]{2,8}\.example\.(com|net|org)')],
            [3, Gen::regex('[a-z]{2,8}\.[a-z]{2,6}')],
            // the root label must not turn an ordinary endpoint into a rejected
            // one either — stripping it is a normalisation, not a filter
            [2, Gen::regex('[a-z]{2,8}\.[a-z]{2,6}\.')],
        ]);
    }
}
