<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Entity;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

/** @internal */
class NmiRecurringCredential implements NmiRecurringCredentialInterface
{
    protected ?int $id = null;

    protected ?PaymentInterface $initialPayment = null;

    protected ?CustomerInterface $customer = null;

    protected ?PaymentMethodInterface $paymentMethod = null;

    protected ?string $vaultId = null;

    protected ?string $billingId = null;

    protected ?string $initialTransactionId = null;

    protected ?string $brand = null;

    protected ?string $lastFour = null;

    protected ?int $expiryMonth = null;

    protected ?int $expiryYear = null;

    /** Changed only by the gateway telling the store what the issuer did. */
    protected string $status = self::STATUS_ACTIVE;

    protected ?\DateTimeImmutable $releasedAt = null;

    protected \DateTimeImmutable $createdAt;

    protected ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInitialPayment(): ?PaymentInterface
    {
        return $this->initialPayment;
    }

    public function setInitialPayment(?PaymentInterface $initialPayment): void
    {
        $this->initialPayment = $initialPayment;
    }

    public function getCustomer(): ?CustomerInterface
    {
        return $this->customer;
    }

    public function setCustomer(?CustomerInterface $customer): void
    {
        $this->customer = $customer;
    }

    public function getPaymentMethod(): ?PaymentMethodInterface
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(?PaymentMethodInterface $paymentMethod): void
    {
        $this->paymentMethod = $paymentMethod;
    }

    public function getVaultId(): ?string
    {
        return $this->vaultId;
    }

    public function setVaultId(?string $vaultId): void
    {
        $this->vaultId = $vaultId;
    }

    public function getBillingId(): ?string
    {
        return $this->billingId;
    }

    public function setBillingId(?string $billingId): void
    {
        $this->billingId = $billingId;
    }

    public function getInitialTransactionId(): ?string
    {
        return $this->initialTransactionId;
    }

    public function setInitialTransactionId(?string $initialTransactionId): void
    {
        $this->initialTransactionId = $initialTransactionId;
    }

    public function getBrand(): ?string
    {
        return $this->brand;
    }

    public function setBrand(?string $brand): void
    {
        $this->brand = $brand;
    }

    public function getLastFour(): ?string
    {
        return $this->lastFour;
    }

    public function setLastFour(?string $lastFour): void
    {
        $this->lastFour = $lastFour;
    }

    public function getExpiryMonth(): ?int
    {
        return $this->expiryMonth;
    }

    public function setExpiryMonth(?int $expiryMonth): void
    {
        $this->expiryMonth = $expiryMonth;
    }

    public function getExpiryYear(): ?int
    {
        return $this->expiryYear;
    }

    public function setExpiryYear(?int $expiryYear): void
    {
        $this->expiryYear = $expiryYear;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function isUsable(): bool
    {
        return self::STATUS_CLOSED !== $this->status;
    }

    /**
     * A card expires at the end of its month, so this compares against the first day of the next.
     *
     * An expiry the gateway never reported reads as not expired rather than as expired: the
     * verification that put the card on file described it, and refusing a charge on a guess would
     * strand an order that could have been paid.
     */
    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        if (null === $this->expiryMonth || null === $this->expiryYear) {
            return false;
        }

        $expiresAfter = (new \DateTimeImmutable())
            ->setDate($this->expiryYear, $this->expiryMonth, 1)
            ->modify('first day of next month')
            ->setTime(0, 0)
        ;

        return ($now ?? new \DateTimeImmutable()) >= $expiresAfter;
    }

    public function getReleasedAt(): ?\DateTimeImmutable
    {
        return $this->releasedAt;
    }

    public function setReleasedAt(?\DateTimeImmutable $releasedAt): void
    {
        $this->releasedAt = $releasedAt;
    }

    public function isReleased(): bool
    {
        return null !== $this->releasedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }
}
