<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Provider;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfiguration;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Payment\Model\PaymentInterface;

/**
 * Who, if anyone, may keep a card on file out of this payment.
 *
 * One answer, asked in two places — the page that offers the option and the handler that acts on
 * it — so a guest cannot be refused in the template and admitted in the handler.
 */
interface NmiCardSavingCustomerProviderInterface
{
    /** The customer entitled to store a card on this payment, or null when nobody is. */
    public function forPayment(PaymentInterface $payment, NmiGatewayConfiguration $configuration): ?CustomerInterface;
}
