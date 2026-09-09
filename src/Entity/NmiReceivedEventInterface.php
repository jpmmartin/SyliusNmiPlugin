<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Entity;

use Sylius\Component\Resource\Model\ResourceInterface;

interface NmiReceivedEventInterface extends ResourceInterface
{
    public function getEventId(): ?string;

    public function setEventId(string $eventId): void;

    public function getEventType(): ?string;

    public function setEventType(string $eventType): void;

    /** Which payment method's endpoint took the delivery, so a shared account can be told apart. */
    public function getPaymentMethodCode(): ?string;

    public function setPaymentMethodCode(string $paymentMethodCode): void;

    /** The delivered body, byte for byte. */
    public function getPayload(): ?string;

    public function setPayload(string $payload): void;

    public function getReceivedAt(): ?\DateTimeImmutable;

    public function setReceivedAt(\DateTimeImmutable $receivedAt): void;
}
