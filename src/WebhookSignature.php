<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks;

use InvalidArgumentException;

/**
 * @api
 */
final readonly class WebhookSignature
{
    public function __construct(
        private int $timestamp,
        private string $value,
    ) {
        if ($timestamp <= 0) {
            throw new InvalidArgumentException('Signature timestamp must be positive');
        }

        if ($value === '') {
            throw new InvalidArgumentException('Signature value must not be empty');
        }
    }

    public static function fromHeaderValue(string $header): self
    {
        $parts = [];

        foreach (explode(',', $header) as $part) {
            $segments = explode('=', $part, 2);

            if (count($segments) !== 2) {
                throw new InvalidArgumentException('Invalid signature header format');
            }

            $parts[trim($segments[0])] = trim($segments[1]);
        }

        if (!isset($parts['t'], $parts['v1'])) {
            throw new InvalidArgumentException('Signature header must contain t and v1 fields');
        }

        if (!preg_match('/^\d+$/', $parts['t'])) {
            throw new InvalidArgumentException('Invalid timestamp in signature header');
        }

        return new self(
            timestamp: (int) $parts['t'],
            value: $parts['v1'],
        );
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function toHeaderValue(): string
    {
        return 't=' . $this->timestamp . ',v1=' . $this->value;
    }
}
