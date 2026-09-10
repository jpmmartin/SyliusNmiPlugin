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
 * Gives the money back before the operator's click is allowed to mark the payment refunded.
 *
 * Before, like the capture and the void: nothing leaves `refunded`, so a refusal after the fact
 * would leave an order claiming it returned money it still holds.
 *
 * @internal
 */
final class RefundPaymentListener
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
        $paymentRequest->setAction(PaymentRequestInterface::ACTION_REFUND);
        $this->paymentRequestRepository->add($paymentRequest);

        $this->announcer->dispatchPaymentRequestCommand($paymentRequest);

        if (PaymentRequestInterface::STATE_COMPLETED === $paymentRequest->getState()) {
            return;
        }

        // The handler decided both what went wrong and how to say it. The gateway's own sentence
        // wins when there is one — it is the only description some refusals have, and it arrives
        // in English whatever the locale. Otherwise the key goes to the flash, which translates
        // it through the `flashes` domain into the operator's own language.
        $responseData = $paymentRequest->getResponseData();
        $detail = $responseData['detail'] ?? null;
        if (is_string($detail) && '' !== $detail) {
            $event->stop($detail);

            return;
        }

        $messageKey = $responseData['message_key'] ?? null;

        $event->stop(is_string($messageKey) && '' !== $messageKey ? $messageKey : 'jpm_martin_sylius_nmi.payment.refund_refused');
    }
}
