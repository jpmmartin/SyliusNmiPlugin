<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Entity;

use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Resource\Model\ResourceInterface;

interface NmiGatewayNoticeInterface extends ResourceInterface
{
    /** A batch that failed to settle. Names no transaction, so it belongs to no order. */
    public const TYPE_SETTLEMENT_FAILURE = 'settlement_failure';

    /** Money taken back by the cardholder's issuer. */
    public const TYPE_CHARGEBACK = 'chargeback';

    /**
     * An event naming a transaction this store does not know.
     *
     * Only ever recorded when an operator asked for it: on a shared gateway account these are the
     * other store's ordinary business and there would be thousands.
     */
    public const TYPE_UNKNOWN_TRANSACTION = 'unknown_transaction';

    public function getType(): ?string;

    public function setType(string $type): void;

    /** The gateway's own handle on it — a batch identifier, a chargeback identifier. */
    public function getReference(): ?string;

    public function setReference(?string $reference): void;

    public function getPaymentMethodCode(): ?string;

    public function setPaymentMethodCode(string $paymentMethodCode): void;

    /** Null when the gateway named nothing this store could resolve to an order. */
    public function getPayment(): ?PaymentInterface;

    public function setPayment(?PaymentInterface $payment): void;

    public function getAmount(): ?int;

    public function setAmount(?int $amount): void;

    public function getCurrencyCode(): ?string;

    public function setCurrencyCode(?string $currencyCode): void;

    public function getReason(): ?string;

    public function setReason(?string $reason): void;

    public function getOccurredAt(): ?\DateTimeImmutable;

    public function setOccurredAt(\DateTimeImmutable $occurredAt): void;
}
