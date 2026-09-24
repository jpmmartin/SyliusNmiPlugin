<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Entity;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Encryption\EncryptionAwareInterface;
use Sylius\Resource\Model\ResourceInterface;

/**
 * A card kept at checkout on a promise of recurring charges, to be charged again for new payments
 * without the shopper.
 *
 * The third kind of card this plugin keeps, and deliberately a record of its own: a card a shopper
 * saved is offered back to them, and a card on file serves one payment and is let go once charged.
 * This one is never offered to anybody and survives its charges; only the store's code charges it,
 * holding its id, and only the store lets it go.
 *
 * It was opened by one payment — the checkout that kept it — and outlives that payment: deleting the
 * order that opened it leaves it in place, and its opening payment reads as none from then on. It
 * belongs to the customer of that order, guest or not, and deleting the customer lets it go.
 *
 * The three gateway identifiers are credentials and are encrypted at rest; the brand, last four
 * digits and expiry are what a receipt prints.
 */
interface NmiRecurringCredentialInterface extends ResourceInterface, EncryptionAwareInterface
{
    /** Chargeable, as far as anyone has been told. */
    public const STATUS_ACTIVE = 'active';

    /** The issuer closed the account behind it. It cannot be charged. */
    public const STATUS_CLOSED = 'closed';

    /** The payment whose checkout kept it, or none once that payment's order has been deleted. */
    public function getInitialPayment(): ?PaymentInterface;

    public function setInitialPayment(?PaymentInterface $initialPayment): void;

    public function getCustomer(): ?CustomerInterface;

    public function setCustomer(?CustomerInterface $customer): void;

    /** The payment method it was kept under, which names the NMI account holding it. */
    public function getPaymentMethod(): ?PaymentMethodInterface;

    public function setPaymentMethod(?PaymentMethodInterface $paymentMethod): void;

    public function getVaultId(): ?string;

    public function setVaultId(?string $vaultId): void;

    public function getBillingId(): ?string;

    public function setBillingId(?string $billingId): void;

    /** The checkout's transaction that first stored the card, which every later charge has to cite. */
    public function getInitialTransactionId(): ?string;

    public function setInitialTransactionId(?string $initialTransactionId): void;

    public function getBrand(): ?string;

    public function setBrand(?string $brand): void;

    public function getLastFour(): ?string;

    public function setLastFour(?string $lastFour): void;

    public function getExpiryMonth(): ?int;

    public function setExpiryMonth(?int $expiryMonth): void;

    public function getExpiryYear(): ?int;

    public function setExpiryYear(?int $expiryYear): void;

    public function getStatus(): string;

    public function setStatus(string $status): void;

    /** Whether the issuer has left it chargeable. Expiry is a separate question — see `isExpired()`. */
    public function isUsable(): bool;

    public function isExpired(?\DateTimeImmutable $now = null): bool;

    public function getReleasedAt(): ?\DateTimeImmutable;

    public function setReleasedAt(?\DateTimeImmutable $releasedAt): void;

    /** Let go by the store, or with its customer: it can never be charged again. */
    public function isReleased(): bool;

    public function getCreatedAt(): \DateTimeImmutable;

    public function getUpdatedAt(): ?\DateTimeImmutable;

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): void;
}
