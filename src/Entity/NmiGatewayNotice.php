<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Entity;

use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Something the gateway reported that an operator has to know about.
 *
 * **Separate from the received-event record, and that is the whole reason it exists.** Every
 * accepted delivery is written down, but that table is pruned — it has to be, because it is the
 * replay guard and it grows without a business reason to keep it. A chargeback matters for months
 * and a failed settlement until somebody has chased it, so neither can live somewhere that is
 * deleted on a retention period measured in days.
 *
 * The payment is nullable and stays that way. A failed settlement names no transaction at all —
 * batch identifier, merchant and processor and nothing else — so there is no order to attach it
 * to, and attaching it to a guess would be worse than leaving it at the account.
 */
class NmiGatewayNotice implements NmiGatewayNoticeInterface
{
    protected ?int $id = null;

    protected ?string $type = null;

    protected ?string $reference = null;

    protected ?string $paymentMethodCode = null;

    protected ?PaymentInterface $payment = null;

    protected ?int $amount = null;

    protected ?string $currencyCode = null;

    protected ?string $reason = null;

    protected ?\DateTimeImmutable $occurredAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): void
    {
        $this->reference = $reference;
    }

    public function getPaymentMethodCode(): ?string
    {
        return $this->paymentMethodCode;
    }

    public function setPaymentMethodCode(string $paymentMethodCode): void
    {
        $this->paymentMethodCode = $paymentMethodCode;
    }

    public function getPayment(): ?PaymentInterface
    {
        return $this->payment;
    }

    public function setPayment(?PaymentInterface $payment): void
    {
        $this->payment = $payment;
    }

    public function getAmount(): ?int
    {
        return $this->amount;
    }

    public function setAmount(?int $amount): void
    {
        $this->amount = $amount;
    }

    public function getCurrencyCode(): ?string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(?string $currencyCode): void
    {
        $this->currencyCode = $currencyCode;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): void
    {
        $this->reason = $reason;
    }

    public function getOccurredAt(): ?\DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeImmutable $occurredAt): void
    {
        $this->occurredAt = $occurredAt;
    }
}
