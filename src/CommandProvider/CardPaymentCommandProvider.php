<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandProvider;

use JpmMartin\SyliusNmiPlugin\Command\CapturePayment;
use JpmMartin\SyliusNmiPlugin\Command\CompleteCardPayment;
use JpmMartin\SyliusNmiPlugin\Command\PrepareCardPayment;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * Taking a card payment needs two round trips — one to hand the browser what it needs, one to
 * charge what the browser sends back — but a payment request offers only a single announcement
 * per state. So the phase is read off the request's own state rather than tracked anywhere:
 * a request that has not started yet gets the first command, one already in progress gets the
 * second.
 *
 * This covers both the sale and the authorise action, because which of the two a store uses is
 * the platform's decision, made from the payment method's configuration before this is reached.
 *
 * @internal
 */
final class CardPaymentCommandProvider implements PaymentRequestCommandProviderInterface
{
    private const HANDLED_ACTIONS = [
        PaymentRequestInterface::ACTION_CAPTURE,
        PaymentRequestInterface::ACTION_AUTHORIZE,
    ];

    public function supports(PaymentRequestInterface $paymentRequest): bool
    {
        return in_array($paymentRequest->getAction(), self::HANDLED_ACTIONS, true);
    }

    public function provide(PaymentRequestInterface $paymentRequest): object
    {
        // The capture action covers two different situations, and the payment says which. A
        // payment already authorised has money reserved at the gateway and only needs claiming;
        // there is no card to collect and no browser involved.
        if (PaymentInterface::STATE_AUTHORIZED === $paymentRequest->getPayment()->getState()) {
            return new CapturePayment($paymentRequest->getId());
        }

        if (PaymentRequestInterface::STATE_PROCESSING === $paymentRequest->getState()) {
            return new CompleteCardPayment($paymentRequest->getId());
        }

        return new PrepareCardPayment($paymentRequest->getId());
    }
}
