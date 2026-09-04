<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\EventListener;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use Sylius\Bundle\PaymentBundle\Announcer\PaymentRequestAnnouncerInterface;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Factory\PaymentRequestFactoryInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;

/**
 * Takes the money back before the operator's click is allowed to cancel the payment.
 *
 * Before, for the same reason the capture is: the payment state machine offers nothing out of
 * cancelled, so a void the gateway refused — because the transaction has already settled, which
 * it will say in words — would leave an order claiming it returned money that never moved.
 */
final class VoidPaymentListener
{
    /**
     * @param PaymentRequestFactoryInterface<PaymentRequestInterface> $paymentRequestFactory
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository
     */
    public function __construct(
        private readonly PaymentRequestFactoryInterface $paymentRequestFactory,
        private readonly PaymentRequestRepositoryInterface $paymentRequestRepository,
        private readonly PaymentRequestAnnouncerInterface $announcer,
    ) {
    }

    public function __invoke(ResourceControllerEvent $event): void
    {
        $payment = $event->getSubject();
        if (!$payment instanceof PaymentInterface) {
            return;
        }

        $paymentMethod = $payment->getMethod();
        if (NmiGatewayFactory::NAME !== $paymentMethod?->getGatewayConfig()?->getFactoryName()) {
            return;
        }

        $paymentRequest = $this->paymentRequestFactory->create($payment, $paymentMethod);
        $paymentRequest->setAction(PaymentRequestInterface::ACTION_CANCEL);
        $this->paymentRequestRepository->add($paymentRequest);

        $this->announcer->dispatchPaymentRequestCommand($paymentRequest);

        if (PaymentRequestInterface::STATE_COMPLETED === $paymentRequest->getState()) {
            return;
        }

        $responseData = $paymentRequest->getResponseData();
        $detail = $responseData['detail'] ?? null;

        $event->stop(is_string($detail) && '' !== $detail ? $detail : 'jpm_martin_sylius_nmi.payment.void_refused');
    }
}
