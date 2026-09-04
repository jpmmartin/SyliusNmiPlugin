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
 * Claims the money before the operator's click is allowed to complete the payment.
 *
 * The platform provides no capture trigger of its own, so the one an operator already has — the
 * complete action on the order screen — is where this belongs. It runs *before* the transition
 * rather than after, because a payment marked completed cannot be moved back: the payment state
 * machine offers nothing out of completed but a refund, so a capture the gateway refused would
 * leave the order permanently claiming money that was never taken.
 *
 * Stopping the event is the platform's own way of saying no: the controller shows the reason and
 * redirects, and the payment is untouched.
 *
 * A guard on the transition would have been the obvious place and is unusable: the admin asks the
 * state machine whether the transition is possible in order to decide whether to draw the button,
 * so a guard would charge the card every time somebody opened the order.
 */
final class CapturePaymentListener
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

        // Only an authorised payment has money waiting to be claimed. Completing a payment from
        // any other state is somebody else's business.
        if (PaymentInterface::STATE_AUTHORIZED !== $payment->getState()) {
            return;
        }

        $paymentRequest = $this->paymentRequestFactory->create($payment, $paymentMethod);
        $paymentRequest->setAction(PaymentRequestInterface::ACTION_CAPTURE);
        $this->paymentRequestRepository->add($paymentRequest);

        $this->announcer->dispatchPaymentRequestCommand($paymentRequest);

        if (PaymentRequestInterface::STATE_COMPLETED === $paymentRequest->getState()) {
            return;
        }

        $responseData = $paymentRequest->getResponseData();
        $detail = $responseData['detail'] ?? null;

        $event->stop(
            is_string($detail) && '' !== $detail ? $detail : 'jpm_martin_sylius_nmi.payment.capture_refused',
        );
    }
}
