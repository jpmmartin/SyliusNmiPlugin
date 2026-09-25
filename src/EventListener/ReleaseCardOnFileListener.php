<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\EventListener;

use JpmMartin\SyliusNmiPlugin\CardOnFile\NmiCardOnFileReleaser;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Releases the card on file once the order screen has completed its payment.
 *
 * On the event that follows the transition, which the platform dispatches after the change has been
 * flushed: the release is queued only once the payment's new state is committed. A cancellation is
 * not this listener's: a payment is cancelled by far more than the order screen, and every one of
 * those saved cancellations lets its card go in the flush that saves it.
 *
 * @internal
 */
final class ReleaseCardOnFileListener
{
    public function __construct(private readonly NmiCardOnFileReleaser $releaser)
    {
    }

    public function __invoke(ResourceControllerEvent $event): void
    {
        $payment = $event->getSubject();
        if (!$payment instanceof PaymentInterface) {
            return;
        }

        if (PaymentInterface::STATE_COMPLETED !== $payment->getState()) {
            return;
        }

        $this->releaser->release($payment);
    }
}
