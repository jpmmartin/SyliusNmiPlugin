<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\EventListener;

use JpmMartin\SyliusNmiPlugin\CardOnFile\NmiCardOnFileReleaser;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Releases the card on file once the order screen has completed or cancelled its payment.
 *
 * On the events that follow the transition, which the platform dispatches after the change has been
 * flushed: the release is queued only once the payment's new state is committed.
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

        if (!in_array($payment->getState(), [PaymentInterface::STATE_COMPLETED, PaymentInterface::STATE_CANCELLED], true)) {
            return;
        }

        $this->releaser->release($payment);
    }
}
