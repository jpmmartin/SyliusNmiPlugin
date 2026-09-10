<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Refund;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

/** Whether a payment method is one of this gateway's. The refund plugin's screens mix every gateway's. */
/** @internal */
final class NmiPaymentMethods
{
    public static function includes(?PaymentMethodInterface $paymentMethod): bool
    {
        return NmiGatewayFactory::NAME === $paymentMethod?->getGatewayConfig()?->getFactoryName();
    }
}
