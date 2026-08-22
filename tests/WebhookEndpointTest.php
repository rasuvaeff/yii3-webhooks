<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks\Tests;

use InvalidArgumentException;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Webhooks\Tests\Support\HostGenerators;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(WebhookEndpoint::class)]
final class WebhookEndpointTest
{
    public function createsWithRequiredFields(): void
    {
        $endpoint = new WebhookEndpoint(
            url: 'https://example.com/webhook',
            secret: 'secret123',
        );

        Assert::same($endpoint->getUrl(), 'https://example.com/webhook');
        Assert::same($endpoint->getSecret(), 'secret123');
        Assert::same($endpoint->getHeaders(), []);
    }

    public function createsWithCustomHeaders(): void
    {
        $endpoint = new WebhookEndpoint(
            url: 'https://example.com/webhook',
            secret: 'secret123',
            headers: ['X-Custom' => 'value'],
        );

        Assert::same($endpoint->getHeaders(), ['X-Custom' => 'value']);
    }

    public function acceptsHttpScheme(): void
    {
        $endpoint = new WebhookEndpoint(
            url: 'http://example.com/webhook',
            secret: 'secret',
        );

        Assert::same($endpoint->getUrl(), 'http://example.com/webhook');
    }

    public function throwsOnInvalidScheme(): void
    {
        try {
            new WebhookEndpoint(url: 'ftp://example.com', secret: 'secret');
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Endpoint URL must use http or https scheme');
        }
    }

    public function throwsOnEmptySecret(): void
    {
        try {
            new WebhookEndpoint(url: 'https://example.com', secret: '');
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Endpoint secret must not be empty');
        }
    }

    public static function invalidSchemeProvider(): iterable
    {
        yield 'ftp' => ['ftp://example.com'];
        yield 'ssh' => ['ssh://example.com'];
        yield 'no scheme' => ['example.com/webhook'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidSchemeProvider')]
    public function throwsOnNonHttpScheme(string $url): void
    {
        Expect::exception(InvalidArgumentException::class);

        new WebhookEndpoint(url: $url, secret: 'secret');
    }

    public function acceptsUppercaseScheme(): void
    {
        $endpoint = new WebhookEndpoint(url: 'HTTPS://example.com/webhook', secret: 'secret');

        Assert::same($endpoint->getUrl(), 'HTTPS://example.com/webhook');
    }

    public function throwsOnMalformedUrl(): void
    {
        try {
            new WebhookEndpoint(url: 'http://:80', secret: 'secret');
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Endpoint URL is malformed');
        }
    }

    /**
     * parse_url() takes everything up to the next slash as the host, so a URL
     * with a space in the authority arrives here looking like a host name.
     */
    public function throwsOnHostThatIsNotAHostName(): void
    {
        try {
            new WebhookEndpoint(url: 'http://exa mple.com/hook', secret: 'secret');
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('not a host name or IP literal');
        }
    }

    public function throwsOnBracketedHostThatIsNotIpv6(): void
    {
        try {
            new WebhookEndpoint(url: 'http://[notanip]/hook', secret: 'secret');
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('not a host name or IP literal');
        }
    }

    public function acceptsPublicIpv6Literal(): void
    {
        $endpoint = new WebhookEndpoint(url: 'https://[2001:db8::1]/hook', secret: 'secret');

        Assert::same($endpoint->getUrl(), 'https://[2001:db8::1]/hook');
    }

    public function throwsOnMissingHost(): void
    {
        try {
            new WebhookEndpoint(url: 'http:', secret: 'secret');
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Endpoint URL must contain a host');
        }
    }

    /**
     * Basic-auth credentials in a URL are a secret, and rasuvaeff/yii3-webhooks-db
     * copies the URL verbatim into every delivery row — so they would end up at
     * rest, and in backups, despite the README's "never the secret".
     */
    public function throwsOnCredentialsInUrl(): void
    {
        try {
            new WebhookEndpoint(url: 'https://user:pass@partner.example.com/hook', secret: 'secret');
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Endpoint URL must not contain credentials');
        }
    }

    public function throwsOnUserWithoutPasswordInUrl(): void
    {
        try {
            new WebhookEndpoint(url: 'https://token@partner.example.com/hook', secret: 'secret');
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Endpoint URL must not contain credentials');
        }
    }

    public static function ssrfHostProvider(): iterable
    {
        yield 'loopback ipv4' => ['http://127.0.0.1/hook'];
        yield 'uppercase localhost' => ['http://LOCALHOST/hook'];
        yield 'mixed-case localhost subdomain' => ['http://Api.LocalHost/hook'];
        // ::ffff:a.b.c.d — the mapped form of a loopback address
        yield 'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/hook'];
        // the same address in hex groups: filter_var() sees an ordinary IPv6
        // address in no range it knows, so only the embedded-IPv4 unwrapping
        // catches it
        yield 'ipv4-compatible loopback in hex groups' => ['http://[::7f00:1]/hook'];
        // ::ffff:1 is *not* a mapped address — it expands to 0:0:0:0:0:0:ffff:1,
        // i.e. ::/96 carrying 255.255.0.1, which is reserved (240/4)
        yield 'ipv4-compatible ipv6' => ['http://[::ffff:1]/hook'];
        yield 'loopback ipv4 alternative' => ['http://127.1.2.3/hook'];
        yield 'loopback ipv6' => ['http://[::1]/hook'];
        yield 'localhost' => ['http://localhost:8080/hook'];
        yield 'localhost subdomain' => ['http://api.localhost/hook'];
        // a single trailing dot is the DNS root label: every resolver strips it,
        // so these are the same names as the two above — but string comparison
        // is not, and `localhost.` used to sail through the whole check
        yield 'localhost with a root label' => ['http://localhost./hook'];
        yield 'localhost subdomain with a root label' => ['http://api.localhost./hook'];
        yield 'loopback ipv4 with a root label' => ['http://127.0.0.1./hook'];
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'private 10/8' => ['http://10.0.0.5:8123/hook'];
        yield 'private 172.16/12' => ['http://172.16.0.1/hook'];
        yield 'private 192.168/16' => ['http://192.168.1.10/hook'];
        yield 'unspecified' => ['http://0.0.0.0/hook'];
        yield 'ipv6 link-local' => ['http://[fe80::1]/hook'];
        yield 'ipv6 unique local' => ['http://[fd00::1]/hook'];
    }

    #[DataProvider('ssrfHostProvider')]
    public function throwsOnPrivateOrLoopbackHost(string $url): void
    {
        try {
            new WebhookEndpoint(url: $url, secret: 'secret');
            Assert::fail('Expected InvalidArgumentException for ' . $url);
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('loopback, private or link-local');
        }
    }

    #[DataProvider('ssrfHostProvider')]
    public function acceptsPrivateHostWhenExplicitlyAllowed(string $url): void
    {
        $endpoint = new WebhookEndpoint(url: $url, secret: 'secret', allowPrivateNetwork: true);

        Assert::same($endpoint->getUrl(), $url);
        Assert::true($endpoint->allowsPrivateNetwork());
    }

    public function doesNotAllowPrivateNetworkByDefault(): void
    {
        $endpoint = new WebhookEndpoint(url: 'https://example.com/webhook', secret: 'secret');

        Assert::false($endpoint->allowsPrivateNetwork());
    }

    public function acceptsPublicIpLiteral(): void
    {
        $endpoint = new WebhookEndpoint(url: 'https://8.8.8.8/hook', secret: 'secret');

        Assert::same($endpoint->getUrl(), 'https://8.8.8.8/hook');
    }

    /**
     * The root label is stripped for the checks only — an accepted URL is still
     * stored exactly as it was written, because that is what a delivery row and
     * every dispatcher downstream receive.
     */
    public function acceptsPublicHostWithRootLabelAndKeepsTheUrlVerbatim(): void
    {
        $endpoint = new WebhookEndpoint(url: 'https://partner.example.com./hook', secret: 'secret');

        Assert::same($endpoint->getUrl(), 'https://partner.example.com./hook');
    }

    /**
     * A host that is nothing but the root label normalises to no host at all,
     * which is what `https://./hook` has always been — it used to pass the
     * host-name pattern and be accepted as an unresolvable name.
     */
    public function throwsOnHostThatIsOnlyARootLabel(): void
    {
        try {
            new WebhookEndpoint(url: 'https://./hook', secret: 'secret');
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Endpoint URL must contain a host');
        }
    }

    /**
     * Only one dot comes off. `localhost..` carries an empty label, no resolver
     * accepts it, and turning it into `localhost` would invent a meaning the
     * name does not have.
     */
    public function stripsExactlyOneRootLabel(): void
    {
        $endpoint = new WebhookEndpoint(url: 'https://localhost../hook', secret: 'secret');

        Assert::same($endpoint->getUrl(), 'https://localhost../hook');
    }

    /**
     * Every http/https URL over a public host name is accepted unchanged — the
     * hardening must reject SSRF targets, not ordinary endpoints.
     */
    #[Property(runs: 200, timeoutMs: 250)]
    public function acceptsOrdinaryHttpUrls(string $url): void
    {
        Assert::same((new WebhookEndpoint(url: $url, secret: 'secret'))->getUrl(), $url);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function acceptsOrdinaryHttpUrlsGenerators(): array
    {
        // scheme://alnum-label.tld[/segments] — never a private host
        return ['url' => Gen::url()];
    }

    /**
     * The accept/reject rule as a property: hosts built from the blocked ranges
     * must be rejected, hosts built from public ranges must be accepted. Both
     * branches are gated, so a regression that turns the check into a constant
     * fails here instead of silently passing half the suite.
     */
    #[Property(runs: 300, generators: [HostGenerators::class, 'verdictFlag'], timeoutMs: 250)]
    public function privateHostsRejectedPublicHostsAccepted(bool $private): void
    {
        $host = Gen::draw($private ? HostGenerators::privateHost() : HostGenerators::publicHost());
        \assert(\is_string($host));

        $rejected = false;

        try {
            new WebhookEndpoint(url: 'https://' . $host . '/hook', secret: 'secret');
        } catch (InvalidArgumentException) {
            $rejected = true;
        }

        Assert::same($rejected, $private);

        Classify::cover(condition: $private, label: 'private host rejected', minPercent: 30.0);
        Classify::cover(condition: !$private, label: 'public host accepted', minPercent: 30.0);
    }

    /** @return iterable<string, array{bool}> */
    public static function privateHostsRejectedPublicHostsAcceptedExamples(): iterable
    {
        yield 'a private host' => [true];
        yield 'a public host' => [false];
    }
}
