<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandProvider;

use JpmMartin\SyliusNmiPlugin\Command\ReportPaymentStatus;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

final class StatusCommandProvider implements PaymentRequestCommandProviderInterface
{
    public function supports(PaymentRequestInterface $paymentRequest): bool
    {
        return PaymentRequestInterface::ACTION_STATUS === $paymentRequest->getAction();
    }

    public function provide(PaymentRequestInterface $paymentRequest): object
    {
        return new ReportPaymentStatus($paymentRequest->getId());
    }
}
