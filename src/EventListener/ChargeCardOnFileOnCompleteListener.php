<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\EventListener;

use JpmMartin\SyliusNmiPlugin\CardOnFile\NmiCardOnFileCharger;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use JpmMartin\SyliusNmiPlugin\Repository\NmiCardOnFileRepositoryInterface;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Charges the card on file before the operator's click is allowed to complete the payment.
 *
 * Without this, *Complete* on a held order would do what it does for any waiting payment: mark it
 * paid, with nothing taken. It runs before the transition for the reason capture does — a completed
 * payment cannot be moved back — and stopping the event is how the platform is told no: the reason is
 * shown and the payment is untouched. The operator's own action applies the transition, which is why
 * the charge leaves the payment alone here.
 *
 * @internal
 */
final class ChargeCardOnFileOnCompleteListener
{
    public function __construct(
        private readonly NmiCardOnFileCharger $charger,
        private readonly NmiCardOnFileRepositoryInterface $cardsOnFile,
    ) {
    }

    public function __invoke(ResourceControllerEvent $event): void
    {
        $payment = $event->getSubject();
        if (!$payment instanceof PaymentInterface) {
            return;
        }

        if (NmiGatewayFactory::NAME !== $payment->getMethod()?->getGatewayConfig()?->getFactoryName()) {
            return;
        }

        // Only a payment waiting with a card on file. An authorised one is capture's business, and a
        // waiting one with no card is completed as the platform always completed it.
        if (PaymentInterface::STATE_PROCESSING !== $payment->getState() || null === $this->cardsOnFile->findHeldBy($payment)) {
            return;
        }

        $outcome = $this->charger->chargeForTheOrderScreen($payment);
        if ($outcome->isApproved()) {
            return;
        }

        // The gateway's own sentence wins when there is one — for a decline it is the issuer's, and
        // the most precise thing there is to say. Otherwise the key, which the flash translates.
        $event->stop(null !== $outcome->reason && '' !== $outcome->reason ? $outcome->reason : $outcome->messageKey);
    }
}
