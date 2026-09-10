<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Command;

use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareInterface;
use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareTrait;

/**
 * Settle an authorisation the gateway is already holding.
 *
 * This is not the capture that takes a card payment at checkout — that one has a card to collect
 * first. Here the money is already reserved and only has to be claimed, so there is no browser
 * involved and nothing for a shopper to do.
 *
 * @internal
 */
final class CapturePayment implements PaymentRequestHashAwareInterface
{
    use PaymentRequestHashAwareTrait;

    public function __construct(?string $hash)
    {
        $this->hash = $hash;
    }
}
