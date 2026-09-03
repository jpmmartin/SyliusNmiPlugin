<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Entity;

use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Resource\Model\ResourceInterface;

/**
 * One row per gateway transaction, keyed on the gateway's own transaction id, so that a payment
 * can be found from nothing but that id. The type is the gateway's word for the operation.
 */
interface NmiTransactionInterface extends ResourceInterface
{
    public const TYPE_SALE = 'sale';

    public const TYPE_AUTH = 'auth';

    public const TYPE_CAPTURE = 'capture';

    public const TYPE_VOID = 'void';

    public const TYPE_REFUND = 'refund';

    /** @var list<string> */
    public const TYPES = [self::TYPE_SALE, self::TYPE_AUTH, self::TYPE_CAPTURE, self::TYPE_VOID, self::TYPE_REFUND];

    public function getPayment(): ?PaymentInterface;

    public function setPayment(?PaymentInterface $payment): void;

    public function getTransactionId(): ?string;

    public function setTransactionId(?string $transactionId): void;

    public function getType(): ?string;

    public function setType(?string $type): void;

    /**
     * The transaction this one acts on, set only for a refund. The gateway issues a refund as a
     * transaction of its own with a fresh identifier, and never records it against the one it
     * reverses — a capture or a void reuse their authorisation's identifier and leave this null.
     *
     * It matters because the gateway tracks the refundable balance per *transaction*: it refuses
     * a refund beyond that balance, and will not report what the balance is.
     */
    public function getParentTransactionId(): ?string;

    public function setParentTransactionId(?string $parentTransactionId): void;

    /** In the currency's minor unit, as Sylius stores every amount. */
    public function getAmount(): ?int;

    public function setAmount(?int $amount): void;

    public function getCurrencyCode(): ?string;

    public function setCurrencyCode(?string $currencyCode): void;

    public function getAuthCode(): ?string;

    public function setAuthCode(?string $authCode): void;

    public function getCreatedAt(): \DateTimeImmutable;

    public function setCreatedAt(\DateTimeImmutable $createdAt): void;

    /** Null until the gateway reports settlement; nothing sets it in this release. */
    public function getSettledAt(): ?\DateTimeImmutable;

    public function setSettledAt(?\DateTimeImmutable $settledAt): void;
}
