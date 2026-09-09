<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Entity;

/**
 * One row per delivery the gateway made and this store accepted.
 *
 * **The row is the idempotency guarantee, and the unique index on `event_id` is the mechanism.**
 * Not a lookup: two deliveries arriving at the same instant both pass a select-then-insert, and the
 * gateway retries for three days, so "it has not happened yet" is a race the store would lose in
 * production and never lose in a test. The insert is attempted, the constraint violation *is* the
 * duplicate, and the second delivery is answered with success so the gateway stops.
 *
 * **Unique on the event identifier alone, not on it together with the payment method.** Two methods
 * pointing at the same NMI account both subscribed would each receive the same event, and applying
 * it twice would be wrong — it describes one thing the gateway did, not two.
 *
 * **The payload is personal data held in the clear**: amounts, masked card details, and depending on
 * the event a billing address and a cardholder's email. Unlike the credentials beside it, it is
 * stored as received, because the signature was computed over exactly these bytes and re-encoding
 * them would destroy the only evidence that the delivery was genuine. What bounds the exposure is
 * the retention period, which is why pruning is a requirement rather than housekeeping.
 */
class NmiReceivedEvent implements NmiReceivedEventInterface
{
    protected ?int $id = null;

    protected ?string $eventId = null;

    protected ?string $eventType = null;

    protected ?string $paymentMethodCode = null;

    protected ?string $payload = null;

    protected ?\DateTimeImmutable $receivedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventId(): ?string
    {
        return $this->eventId;
    }

    public function setEventId(string $eventId): void
    {
        $this->eventId = $eventId;
    }

    public function getEventType(): ?string
    {
        return $this->eventType;
    }

    public function setEventType(string $eventType): void
    {
        $this->eventType = $eventType;
    }

    public function getPaymentMethodCode(): ?string
    {
        return $this->paymentMethodCode;
    }

    public function setPaymentMethodCode(string $paymentMethodCode): void
    {
        $this->paymentMethodCode = $paymentMethodCode;
    }

    public function getPayload(): ?string
    {
        return $this->payload;
    }

    public function setPayload(string $payload): void
    {
        $this->payload = $payload;
    }

    public function getReceivedAt(): ?\DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function setReceivedAt(\DateTimeImmutable $receivedAt): void
    {
        $this->receivedAt = $receivedAt;
    }
}
