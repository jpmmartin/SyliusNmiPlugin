<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Entity;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

class NmiStoredCard implements NmiStoredCardInterface
{
    protected ?int $id = null;

    protected ?CustomerInterface $customer = null;

    protected ?PaymentMethodInterface $paymentMethod = null;

    protected ?string $vaultId = null;

    protected ?string $billingId = null;

    protected ?string $vaultingTransactionId = null;

    protected ?string $brand = null;

    protected ?string $lastFour = null;

    protected ?int $expiryMonth = null;

    protected ?int $expiryYear = null;

    protected bool $default = false;

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

    public function getVaultingTransactionId(): ?string
    {
        return $this->vaultingTransactionId;
    }

    public function setVaultingTransactionId(?string $vaultingTransactionId): void
    {
        $this->vaultingTransactionId = $vaultingTransactionId;
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

    public function isDefault(): bool
    {
        return $this->default;
    }

    public function setDefault(bool $default): void
    {
        $this->default = $default;
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

    /**
     * A card expires at the end of its month, which is why this compares against the first day of
     * the next one rather than the first of its own.
     *
     * The expiry is the one captured when the card was stored. Until the webhooks change lands
     * nothing updates it, so a card the issuer renewed reads as expired here — a false negative
     * this plugin documents rather than hides.
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
}
