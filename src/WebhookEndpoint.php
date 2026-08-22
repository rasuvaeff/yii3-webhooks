<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks;

use InvalidArgumentException;

/**
 * Where a webhook is delivered, and the secret it is signed with.
 *
 * In any product that lets its users register endpoints the URL is
 * attacker-controlled input, so the constructor treats it as such: the scheme
 * must be http or https, the host must be there, credentials must not be in the
 * URL, and a literal address inside a loopback, private, link-local or
 * otherwise reserved range is rejected. Deliveries to an internal network are
 * an explicit decision — `allowPrivateNetwork: true`.
 *
 * A host name is *not* resolved here: DNS is not the constructor's business and
 * an answer taken now says nothing about the answer at delivery time. Blocking
 * a name that resolves into the internal network, and re-checking every
 * redirect hop, belongs to the dispatcher — see the Security section of the
 * README.
 *
 * @api
 */
final readonly class WebhookEndpoint
{
    /** `::/96` — an IPv4 address carried in the low 32 bits of an IPv6 one. */
    private const string IPV4_COMPATIBLE_PREFIX = "\0\0\0\0\0\0\0\0\0\0\0\0";

    /** `::ffff:0:0/96` — the same, in the form a dual-stack socket produces. */
    private const string IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    /**
     * @param array<string, string> $headers Additional headers to include in delivery
     * @param bool $allowPrivateNetwork accept loopback/private/link-local hosts —
     *        for deliveries that are meant to stay inside the perimeter
     */
    public function __construct(
        private string $url,
        #[\SensitiveParameter]
        private string $secret,
        private array $headers = [],
        private bool $allowPrivateNetwork = false,
    ) {
        $parts = parse_url($url);

        if ($parts === false) {
            throw new InvalidArgumentException('Endpoint URL is malformed');
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException('Endpoint URL must use http or https scheme');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            // credentials in a URL are a secret that every stored delivery row
            // would carry; endpoint headers exist for that and never reach storage
            throw new InvalidArgumentException('Endpoint URL must not contain credentials');
        }

        $host = $parts['host'] ?? '';

        if ($host === '') {
            throw new InvalidArgumentException('Endpoint URL must contain a host');
        }

        if (!self::isValidHost($host)) {
            throw new InvalidArgumentException('Endpoint URL host is not a host name or IP literal');
        }

        if (!$allowPrivateNetwork && self::isPrivateHost($host)) {
            throw new InvalidArgumentException(
                'Endpoint URL must not point at a loopback, private or link-local address',
            );
        }

        if ($secret === '') {
            throw new InvalidArgumentException('Endpoint secret must not be empty');
        }
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function allowsPrivateNetwork(): bool
    {
        return $this->allowPrivateNetwork;
    }

    /**
     * `parse_url()` hands back whatever sat between `//` and the next `/`, so a
     * host with a space or a control character in it reaches this far. Nothing
     * can deliver to one, and a client that tries is a request-smuggling risk.
     */
    private static function isValidHost(string $host): bool
    {
        $literal = self::ipv6Literal($host);

        if ($literal !== null) {
            return filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        return preg_match('/^[A-Za-z0-9._-]+\z/', $host) === 1;
    }

    /**
     * The address inside an IPv6 literal's brackets, as the host appears in a
     * URL, or null when the host is not one.
     *
     * Anchored with `\A`/`\z` rather than `^`/`$` for the same reason the rest
     * of this monorepo is: the dollar form also matches before a trailing
     * newline, and the caret form stops anchoring the moment anyone adds `/m`.
     */
    private static function ipv6Literal(string $host): ?string
    {
        return preg_match('/\A\[(?<address>.+)]\z/', $host, $matches) === 1
            ? $matches['address']
            : null;
    }

    private static function isPrivateHost(string $host): bool
    {
        $name = strtolower($host);

        if ($name === 'localhost' || str_ends_with($name, '.localhost')) {
            return true;
        }

        // an IPv6 literal reaches us bracketed, as it appears in the URL
        $ip = self::ipv6Literal($host) ?? $host;
        $packed = inet_pton($ip);

        if ($packed === false) {
            // a host name — there is nothing to classify without resolving it,
            // and resolving belongs to the dispatcher
            return false;
        }

        if (self::isBlockedAddress($ip)) {
            return true;
        }

        $embedded = self::embeddedIpv4($packed);

        return $embedded !== null && self::isBlockedAddress($embedded);
    }

    /**
     * NO_PRIV_RANGE covers 10/8, 172.16/12, 192.168/16 and fc00::/7;
     * NO_RES_RANGE covers 0/8, 127/8, 169.254/16 (cloud metadata), 240/4, `::`,
     * `::1`, `::ffff:0:0/96` and fe80::/10.
     */
    private static function isBlockedAddress(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    /**
     * The IPv4 address embedded in an IPv6 one — `::a.b.c.d` (IPv4-compatible)
     * or `::ffff:a.b.c.d` (IPv4-mapped) — or null when there is none.
     *
     * The unwrapping is not redundant with {@see self::isBlockedAddress()}: PHP
     * validates the dotted tail of `::127.0.0.1` as IPv4 and so blocks it, but
     * the exact same address written in hex groups — `::7f00:1` — is a plain
     * IPv6 address to `filter_var()`, outside every range it knows, and would
     * otherwise reach the delivery worker as a loopback target.
     *
     * @param string $packed the address in its `inet_pton()` binary form
     */
    private static function embeddedIpv4(string $packed): ?string
    {
        // a packed IPv4 is four bytes, so its prefix can never be twelve long
        // and falls through the check below
        $prefix = substr($packed, 0, 12);

        if ($prefix !== self::IPV4_COMPATIBLE_PREFIX && $prefix !== self::IPV4_MAPPED_PREFIX) {
            return null;
        }

        $address = inet_ntop(substr($packed, 12));

        return \is_string($address) ? $address : null;
    }
}
