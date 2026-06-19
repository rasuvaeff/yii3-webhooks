<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * @api
 */
final readonly class WebhookEvent
{
    public function __construct(
        private string $id,
        private string $type,
        private string $payload,
        private DateTimeImmutable $occurredAt,
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('Event id must not be empty');
        }

        if ($type === '') {
            throw new InvalidArgumentException('Event type must not be empty');
        }
    }

    public static function create(
        string $type,
        string $payload,
        ?DateTimeImmutable $occurredAt = null,
    ): self {
        return new self(
            id: bin2hex(random_bytes(16)),
            type: $type,
            payload: $payload,
            occurredAt: $occurredAt ?? new DateTimeImmutable(),
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
