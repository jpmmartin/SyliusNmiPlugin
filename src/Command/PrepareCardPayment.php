<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Command;

use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareInterface;
use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareTrait;

/**
 * First phase: write what the browser needs in order to collect a card, and move the request
 * to processing. Nothing is charged here, and the gateway is not called at all.
 *
 * The hash is the whole message because the request itself carries everything else, and
 * because the bus routes on the interface rather than on this class.
 */
final class PrepareCardPayment implements PaymentRequestHashAwareInterface
{
    use PaymentRequestHashAwareTrait;

    public function __construct(?string $hash)
    {
        $this->hash = $hash;
    }
}
