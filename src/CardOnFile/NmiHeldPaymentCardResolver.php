<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CardOnFile;

use JpmMartin\SyliusNmiPlugin\Entity\NmiCardOnFileInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiCardOnFileRepositoryInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiRecurringCredentialRepositoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * What charges a held payment: the card put on file for it, or — when the payment opened recurring
 * charges, and so kept its card as a recurring credential instead — that credential, while the
 * payment still waits and the credential has not been let go.
 *
 * One answer for every door that charges a held payment, so that a store opting into recurring
 * charges keeps charging its held orders the way it already did.
 *
 * @internal
 */
final class NmiHeldPaymentCardResolver
{
    public function __construct(
        private readonly NmiCardOnFileRepositoryInterface $cardsOnFile,
        private readonly NmiRecurringCredentialRepositoryInterface $recurringCredentials,
    ) {
    }

    public function resolve(PaymentInterface $payment): NmiCardOnFileInterface|NmiRecurringCredentialInterface|null
    {
        $card = $this->cardsOnFile->findHeldBy($payment);
        if (null !== $card) {
            return $card;
        }

        // Only the held payment itself: a credential is charged for any other payment by the store's
        // own call to the recurring charger, never by a door meant for cards on file.
        if (PaymentInterface::STATE_PROCESSING !== $payment->getState()) {
            return null;
        }

        $credential = $this->recurringCredentials->findOpenedBy($payment);

        return null !== $credential && !$credential->isReleased() ? $credential : null;
    }
}
