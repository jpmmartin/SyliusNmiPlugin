<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Entity;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Encryption\EncryptionAwareInterface;
use Sylius\Resource\Model\ResourceInterface;

/**
 * A card the shopper asked the gateway to keep.
 *
 * The store never holds the card: it holds three identifiers the gateway issued, and four fields
 * that are only there so a customer can recognise which card they are choosing. The identifiers
 * are credentials and are encrypted; the four are what is already printed on a receipt.
 *
 * `EncryptionAwareInterface` is the platform's marker for an entity its own encrypter may act on.
 */
interface NmiStoredCardInterface extends ResourceInterface, EncryptionAwareInterface
{
    /** Usable, as far as anyone has been told. */
    public const STATUS_ACTIVE = 'active';

    /** The issuer closed the account behind it. It is not offered and cannot be charged. */
    public const STATUS_CLOSED = 'closed';

    /**
     * The issuer asked that the cardholder be contacted about it.
     *
     * Still offered, deliberately: the issuer said to talk to them, not that the card stopped
     * working, and refusing a card the issuer has not refused would cost a sale on a guess.
     */
    public const STATUS_NEEDS_ATTENTION = 'needs_attention';

    public function getStatus(): string;

    public function setStatus(string $status): void;

    /** Whether this card may still be offered and charged. */
    public function isUsable(): bool;

    public function getCustomer(): ?CustomerInterface;

    public function setCustomer(?CustomerInterface $customer): void;

    public function getPaymentMethod(): ?PaymentMethodInterface;

    public function setPaymentMethod(?PaymentMethodInterface $paymentMethod): void;

    /** The gateway's vault reference: what a later charge is made against. */
    public function getVaultId(): ?string;

    public function setVaultId(?string $vaultId): void;

    /** The billing record inside that vault entry; a vault entry may hold more than one. */
    public function getBillingId(): ?string;

    public function setBillingId(?string $billingId): void;

    /**
     * The transaction that first stored this card, when there was one.
     *
     * Null for a card added from the account area: that call reports no transaction, which was
     * established against the gateway rather than assumed. Nothing requires it — a stored card
     * charges without it — so it is kept for the card networks' benefit, not as a precondition.
     */
    public function getVaultingTransactionId(): ?string;

    public function setVaultingTransactionId(?string $vaultingTransactionId): void;

    public function getBrand(): ?string;

    public function setBrand(?string $brand): void;

    public function getLastFour(): ?string;

    public function setLastFour(?string $lastFour): void;

    public function getExpiryMonth(): ?int;

    public function setExpiryMonth(?int $expiryMonth): void;

    public function getExpiryYear(): ?int;

    public function setExpiryYear(?int $expiryYear): void;

    public function isDefault(): bool;

    public function setDefault(bool $default): void;

    public function getCreatedAt(): \DateTimeImmutable;

    public function getUpdatedAt(): ?\DateTimeImmutable;

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): void;

    /** Whether the expiry captured when the card was stored has passed. */
    public function isExpired(?\DateTimeImmutable $now = null): bool;
}
