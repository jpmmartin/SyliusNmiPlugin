<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandProvider;

use JpmMartin\SyliusNmiPlugin\Command\RefundPayment;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/** @internal */
final class RefundCommandProvider implements PaymentRequestCommandProviderInterface
{
    public function supports(PaymentRequestInterface $paymentRequest): bool
    {
        return PaymentRequestInterface::ACTION_REFUND === $paymentRequest->getAction();
    }

    public function provide(PaymentRequestInterface $paymentRequest): object
    {
        return new RefundPayment($paymentRequest->getId());
    }
}
