<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Command;

use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareInterface;
use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareTrait;

/**
 * Answers the platform's "what happened?" after a payment.
 *
 * The pay flow ends by minting a request for the status action, and every gateway has to handle
 * it or the shopper's last redirect fails. The answer needs no gateway call: whatever the charge
 * decided is already on the payment, and the gateway has nothing to add that would change it.
 */
final class ReportPaymentStatus implements PaymentRequestHashAwareInterface
{
    use PaymentRequestHashAwareTrait;

    public function __construct(?string $hash)
    {
        $this->hash = $hash;
    }
}
