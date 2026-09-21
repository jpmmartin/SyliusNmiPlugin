<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Command;

use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareInterface;
use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareTrait;

/**
 * Charge the card on file for a payment, with nobody present.
 *
 * **No command provider produces this message.** The only way to it is the charger service, which
 * dispatches it directly — because the shop API lets a client name any payment-request action, and
 * an action that led here would let whoever holds an order's token charge it on their own say-so.
 *
 * @internal
 */
final class ChargeCardOnFile implements PaymentRequestHashAwareInterface
{
    use PaymentRequestHashAwareTrait;

    public function __construct(?string $hash)
    {
        $this->hash = $hash;
    }
}
