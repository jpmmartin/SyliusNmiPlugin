<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandProvider;

use JpmMartin\SyliusNmiPlugin\Command\RefuseRefundOrVoid;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * Answers an announced void request with a refusal, always — for the reason the refund's provider
 * gives: this plugin sends its own voids itself, so an announced one came from somewhere else.
 *
 * @internal
 */
final class CancelCommandProvider implements PaymentRequestCommandProviderInterface
{
    public function supports(PaymentRequestInterface $paymentRequest): bool
    {
        return PaymentRequestInterface::ACTION_CANCEL === $paymentRequest->getAction();
    }

    public function provide(PaymentRequestInterface $paymentRequest): object
    {
        return new RefuseRefundOrVoid($paymentRequest->getId());
    }
}
