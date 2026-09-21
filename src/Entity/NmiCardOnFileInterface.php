<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Entity;

use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Encryption\EncryptionAwareInterface;
use Sylius\Resource\Model\ResourceInterface;

/**
 * A card put on file for one payment, to be charged later without the shopper.
 *
 * Not a card the shopper saved for themselves, and deliberately not the same record: it belongs to
 * a payment rather than to a customer, it is never listed in an account or offered at a checkout,
 * and once its payment has been charged or cancelled it is released at the gateway. What the
 * shopper agreed to when it was put on file is this one order, so that is all it can pay for.
 *
 * The three gateway identifiers are credentials and are encrypted at rest; the brand, last four
 * digits and expiry are what a receipt prints.
 *
 * @internal
 */
interface NmiCardOnFileInterface extends ResourceInterface, EncryptionAwareInterface
{
    /** Chargeable, as far as anyone has been told. */
    public const STATUS_ACTIVE = 'active';

    /** The issuer closed the account behind it. It cannot be charged. */
    public const STATUS_CLOSED = 'closed';

    public function getPayment(): ?PaymentInterface;

    public function setPayment(?PaymentInterface $payment): void;

    /** The payment method it was put on file under, which names the NMI account holding it. */
    public function getPaymentMethod(): ?PaymentMethodInterface;

    public function setPaymentMethod(?PaymentMethodInterface $paymentMethod): void;

    public function getVaultId(): ?string;

    public function setVaultId(?string $vaultId): void;

    public function getBillingId(): ?string;

    public function setBillingId(?string $billingId): void;

    /** The verification that put the card on file, which every later charge has to cite. */
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

    /** Removed from the gateway, or on its way there: it can never be charged again. */
    public function isReleased(): bool;

    public function getCreatedAt(): \DateTimeImmutable;

    public function getUpdatedAt(): ?\DateTimeImmutable;

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): void;
}
