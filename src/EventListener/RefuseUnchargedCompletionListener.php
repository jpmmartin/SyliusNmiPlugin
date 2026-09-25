<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\EventListener;

use JpmMartin\SyliusNmiPlugin\CardOnFile\NmiApprovedCharges;
use JpmMartin\SyliusNmiPlugin\CardOnFile\NmiHeldPaymentCardResolver;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use Sylius\Bundle\ApiBundle\Exception\StateMachineTransitionFailedException;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\Workflow\Event\TransitionEvent;

/**
 * Refuses to complete a held payment that nothing has charged, whoever applies the transition.
 *
 * A held payment waits for its card to be charged; completing it any other way marks an order paid
 * with no money taken and the card still held. The order screen charges first, and the plugin's own
 * charges complete what they charged — both record the approval before the transition runs. The
 * platform's admin API, and a store's own code, apply the transition directly, with none of the order
 * screen's events: this is where they are stopped.
 *
 * **On the transition, not on the guard.** A guard also answers whether the transition is possible,
 * and the order screen draws its *Complete* action only when it is — the very action that charges a
 * held payment. The transition runs for an application alone, before the payment's state changes, so
 * a refusal here leaves nothing half done. The exception is the one the admin API already answers
 * with 422 for a transition it cannot apply.
 *
 * @internal
 */
final class RefuseUnchargedCompletionListener
{
    public function __construct(
        private readonly NmiHeldPaymentCardResolver $heldPayments,
        private readonly NmiApprovedCharges $approvedCharges,
    ) {
    }

    public function __invoke(TransitionEvent $event): void
    {
        $payment = $event->getSubject();
        if (!$payment instanceof PaymentInterface) {
            return;
        }

        if (NmiGatewayFactory::NAME !== $payment->getMethod()?->getGatewayConfig()?->getFactoryName()) {
            return;
        }

        // The approval first: it is in memory, and it is the answer for every charge the plugin makes.
        if ($this->approvedCharges->isApproved($payment) || null === $this->heldPayments->resolve($payment)) {
            return;
        }

        throw new StateMachineTransitionFailedException(sprintf(
            'Payment %s holds a card that has not been charged: it is completed only by an approved charge, from the order screen or the charger.',
            (string) $payment->getId(),
        ));
    }
}
