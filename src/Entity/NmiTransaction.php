<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Entity;

use Sylius\Component\Core\Model\PaymentInterface;

class NmiTransaction implements NmiTransactionInterface
{
    protected ?int $id = null;

    protected ?PaymentInterface $payment = null;

    protected ?string $transactionId = null;

    protected ?string $type = null;

    protected ?string $parentTransactionId = null;

    protected ?int $amount = null;

    protected ?string $currencyCode = null;

    protected ?string $authCode = null;

    protected \DateTimeImmutable $createdAt;

    protected ?\DateTimeImmutable $settledAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPayment(): ?PaymentInterface
    {
        return $this->payment;
    }

    public function setPayment(?PaymentInterface $payment): void
    {
        $this->payment = $payment;
    }

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    public function setTransactionId(?string $transactionId): void
    {
        $this->transactionId = $transactionId;
    }

    public function getParentTransactionId(): ?string
    {
        return $this->parentTransactionId;
    }

    public function setParentTransactionId(?string $parentTransactionId): void
    {
        $this->parentTransactionId = $parentTransactionId;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): void
    {
        $this->type = $type;
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

    public function getAuthCode(): ?string
    {
        return $this->authCode;
    }

    public function setAuthCode(?string $authCode): void
    {
        $this->authCode = $authCode;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getSettledAt(): ?\DateTimeImmutable
    {
        return $this->settledAt;
    }

    public function setSettledAt(?\DateTimeImmutable $settledAt): void
    {
        $this->settledAt = $settledAt;
    }
}
