<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandProvider;

use JpmMartin\SyliusNmiPlugin\Command\CapturePayment;
use JpmMartin\SyliusNmiPlugin\Command\CompleteCardPayment;
use JpmMartin\SyliusNmiPlugin\Command\PrepareCardPayment;
use JpmMartin\SyliusNmiPlugin\Command\PutCardOnFile;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
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
            // The one branch this change adds, and it is taken only when the payment method says
            // so. Read from the method's configuration rather than from the action: a client of
            // the shop API chooses the action, and must not be able to be charged on the spot on a
            // method whose merchant asked to take payment later.
            return self::takesPaymentLater($paymentRequest)
                ? new PutCardOnFile($paymentRequest->getId())
                : new CompleteCardPayment($paymentRequest->getId());
        }

        return new PrepareCardPayment($paymentRequest->getId());
    }

    /** Absent means off, which is every configuration stored before the setting existed. */
    private static function takesPaymentLater(PaymentRequestInterface $paymentRequest): bool
    {
        $config = $paymentRequest->getMethod()->getGatewayConfig()?->getConfig() ?? [];

        return (bool) ($config[NmiGatewayFactory::CONFIG_TAKE_PAYMENT_LATER] ?? false);
    }
}
