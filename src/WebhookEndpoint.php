<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks;

use InvalidArgumentException;

/**
 * @api
 */
final readonly class WebhookEndpoint
{
    /**
     * @param array<string, string> $headers Additional headers to include in delivery
     */
    public function __construct(
        private string $url,
        #[\SensitiveParameter]
        private string $secret,
        private array $headers = [],
    ) {
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            throw new InvalidArgumentException('Endpoint URL must use http or https scheme');
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
}
