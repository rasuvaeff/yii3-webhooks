<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Webhooks;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * @api
 */
final readonly class WebhookDelivery
{
    public function __construct(
        private string $id,
        private string $eventId,
        private string $eventType,
        private string $endpointUrl,
        private WebhookDeliveryStatus $status,
        private DateTimeImmutable $createdAt,
        private int $attempts = 0,
        private ?DateTimeImmutable $lastAttemptAt = null,
        private ?string $lastError = null,
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('Delivery id must not be empty');
        }

        if ($eventId === '') {
            throw new InvalidArgumentException('Delivery eventId must not be empty');
        }

        if ($attempts < 0) {
            throw new InvalidArgumentException('Attempts must be non-negative');
        }
    }

    public static function create(
        WebhookEvent $event,
        WebhookEndpoint $endpoint,
        ?DateTimeImmutable $createdAt = null,
    ): self {
        return new self(
            id: bin2hex(random_bytes(16)),
            eventId: $event->getId(),
            eventType: $event->getType(),
            endpointUrl: $endpoint->getUrl(),
            status: WebhookDeliveryStatus::Pending,
            createdAt: $createdAt ?? new DateTimeImmutable(),
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getEndpointUrl(): string
    {
        return $this->endpointUrl;
    }

    public function getStatus(): WebhookDeliveryStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getLastAttemptAt(): ?DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function withStatus(WebhookDeliveryStatus $status): self
    {
        return new self(
            id: $this->id,
            eventId: $this->eventId,
            eventType: $this->eventType,
            endpointUrl: $this->endpointUrl,
            status: $status,
            createdAt: $this->createdAt,
            attempts: $this->attempts,
            lastAttemptAt: $this->lastAttemptAt,
            lastError: $this->lastError,
        );
    }

    public function withAttempt(DateTimeImmutable $at, ?string $error = null): self
    {
        return new self(
            id: $this->id,
            eventId: $this->eventId,
            eventType: $this->eventType,
            endpointUrl: $this->endpointUrl,
            status: $this->status,
            createdAt: $this->createdAt,
            attempts: $this->attempts + 1,
            lastAttemptAt: $at,
            lastError: $error,
        );
    }
}
