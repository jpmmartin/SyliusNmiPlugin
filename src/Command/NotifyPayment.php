<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Command;

use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareInterface;
use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareTrait;

/**
 * Something the gateway did outside the store, arriving as an event.
 *
 * It travels as a payment request like every other operation this plugin performs, so the audit
 * trail does not fork: an operator looking at a payment sees what the store asked the gateway and
 * what the gateway told the store in the same list, distinguished by the action.
 *
 * @internal
 */
final class NotifyPayment implements PaymentRequestHashAwareInterface
{
    use PaymentRequestHashAwareTrait;

    public function __construct(?string $hash)
    {
        $this->hash = $hash;
    }
}
