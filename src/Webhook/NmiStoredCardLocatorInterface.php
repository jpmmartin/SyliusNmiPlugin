<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Webhook;

use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

interface NmiStoredCardLocatorInterface
{
    /**
     * The card this gateway reference belongs to, or null when this store holds none.
     *
     * Null is ordinary: a gateway account shared with another store reports that store's cards
     * too, and they are not this one's to update.
     */
    public function locate(string $vaultId, PaymentMethodInterface $paymentMethod): ?NmiStoredCardInterface;
}
