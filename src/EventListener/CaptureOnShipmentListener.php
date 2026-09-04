<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\EventListener;

use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Announcer\PaymentRequestAnnouncerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Payment\Factory\PaymentRequestFactoryInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\Workflow\Event\Event;

/**
 * Claims an authorisation when the goods go out.
 *
 * Unlike the operator's own capture, this cannot refuse the thing that triggered it: the parcel
 * has shipped, and that is a fact about the world. A gateway that refuses the capture therefore
 * leaves the payment authorised with the reason recorded, for the operator to deal with, rather
 * than pretending the shipment did not happen.
 */
final class CaptureOnShipmentListener
{
    /**
     * @param PaymentRequestFactoryInterface<PaymentRequestInterface> $paymentRequestFactory
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository
     */
    public function __construct(
        private readonly PaymentRequestFactoryInterface $paymentRequestFactory,
        private readonly PaymentRequestRepositoryInterface $paymentRequestRepository,
        private readonly PaymentRequestAnnouncerInterface $announcer,
        private readonly NmiTransactionRepositoryInterface $transactionRepository,
        private readonly StateMachineInterface $stateMachine,
    ) {
    }

    public function __invoke(Event $event): void
    {
        $shipment = $event->getSubject();
        if (!$shipment instanceof ShipmentInterface) {
            return;
        }

        $order = $shipment->getOrder();
        if (null === $order) {
            return;
        }

        foreach ($order->getPayments() as $payment) {
            if ($payment instanceof PaymentInterface && $this->isCapturable($payment)) {
                $this->capture($payment);
            }
        }
    }

    /**
     * Two guards, both this plugin's own. The platform's duplicate suppression is not one of them:
     * it is consulted through a method the announcer never calls, so relying on it would be
     * relying on nothing.
     */
    private function isCapturable(PaymentInterface $payment): bool
    {
        $paymentMethod = $payment->getMethod();
        if (NmiGatewayFactory::NAME !== $paymentMethod?->getGatewayConfig()?->getFactoryName()) {
            return false;
        }

        // An order shipped in several parcels reaches here once per parcel. The first capture
        // moves the payment out of authorised, and a capture already on record says the same
        // thing a second way — cheap, and true even if the payment's state were ever repaired
        // by hand.
        return PaymentInterface::STATE_AUTHORIZED === $payment->getState() &&
            null === $this->transactionRepository->findLatestForPayment($payment, NmiTransactionInterface::TYPE_CAPTURE);
    }

    private function capture(PaymentInterface $payment): void
    {
        $paymentMethod = $payment->getMethod();
        if (null === $paymentMethod) {
            return;
        }

        $paymentRequest = $this->paymentRequestFactory->create($payment, $paymentMethod);
        $paymentRequest->setAction(PaymentRequestInterface::ACTION_CAPTURE);
        $this->paymentRequestRepository->add($paymentRequest);

        $this->announcer->dispatchPaymentRequestCommand($paymentRequest);

        if (PaymentRequestInterface::STATE_COMPLETED !== $paymentRequest->getState()) {
            // The reason is on the payment request, which the admin already shows. The payment
            // stays authorised so an operator can try again from the order screen.
            return;
        }

        $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
    }
}
