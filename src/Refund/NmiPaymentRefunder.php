<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Refund;

use JpmMartin\SyliusNmiPlugin\Command\RefundPayment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Factory\PaymentRequestFactoryInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Asks the gateway to give a whole payment's money back, and says why not when it would not.
 *
 * The one place such a refund starts, whoever is marking the payment refunded: the order screen's
 * *Refund*, before its transition, and the transition itself when anything else applies it. Either
 * way the money moves first; the payment request's handler decides between a void and a refund.
 *
 * **Sent, not announced.** The command goes to the payment-request bus directly, and the provider for
 * the `refund` action refuses every request announced to it. That is what keeps a shop client naming
 * `refund` — which Sylius before 2.2.9 accepts — from getting its money back with the order still paid.
 *
 * @internal
 */
final class NmiPaymentRefunder
{
    public const DEFAULT_REFUSAL = 'jpm_martin_sylius_nmi.payment.refund_refused';

    /**
     * @param PaymentRequestFactoryInterface<PaymentRequestInterface> $paymentRequestFactory
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository
     */
    public function __construct(
        private readonly PaymentRequestFactoryInterface $paymentRequestFactory,
        private readonly PaymentRequestRepositoryInterface $paymentRequestRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly NmiRefundedPayments $refundedPayments,
    ) {
    }

    /**
     * Null once the money is back. Otherwise the refusal: the gateway's own sentence when there is
     * one — the only description some refusals have, and it arrives in English whatever the locale
     * — or the `flashes` key the handler chose.
     */
    public function refund(PaymentInterface $payment, PaymentMethodInterface $paymentMethod): ?string
    {
        $paymentRequest = $this->paymentRequestFactory->create($payment, $paymentMethod);
        $paymentRequest->setAction(PaymentRequestInterface::ACTION_REFUND);
        $this->paymentRequestRepository->add($paymentRequest);

        $this->commandBus->dispatch(new RefundPayment($paymentRequest->getId()));

        if (PaymentRequestInterface::STATE_COMPLETED === $paymentRequest->getState()) {
            $this->refundedPayments->markRefunded($payment);

            return null;
        }

        $responseData = $paymentRequest->getResponseData();
        $detail = $responseData['detail'] ?? null;
        if (is_string($detail) && '' !== $detail) {
            return $detail;
        }

        $messageKey = $responseData['message_key'] ?? null;

        return is_string($messageKey) && '' !== $messageKey ? $messageKey : self::DEFAULT_REFUSAL;
    }
}
