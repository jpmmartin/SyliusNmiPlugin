<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway;

use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

interface NmiGatewayConfigurationProviderInterface
{
    /**
     * @throws \InvalidArgumentException when the payment method is not an NMI one
     * @throws NmiGatewayException when the stored configuration is unusable
     */
    public function fromPaymentMethod(PaymentMethodInterface $paymentMethod): NmiGatewayConfiguration;
}
