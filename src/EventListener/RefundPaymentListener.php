<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\EventListener;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use JpmMartin\SyliusNmiPlugin\Refund\NmiPaymentRefunder;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Gives the money back before the operator's click is allowed to mark the payment refunded.
 *
 * Before, like the capture and the void: nothing leaves `refunded`, so a refusal after the fact
 * would leave an order claiming it returned money it still holds.
 *
 * **Sylius's own *Refund* fires no refund event.** Its route declares no `event`, so the controller
 * announces it as an update — `sylius.payment.pre_update` — and only a route that says
 * `event: refund` reaches `sylius.payment.pre_refund`. Both are listened to; the update only when the
 * route it came from applies the payment's `refund`, which every other update leaves out.
 *
 * Stopping the event is how the order screen says no, with the reason in a flash. Every other way of
 * marking a payment refunded is answered by the transition's own listener instead.
 *
 * @internal
 */
final class RefundPaymentListener
{
    public function __construct(
        private readonly NmiPaymentRefunder $refunder,
        private readonly StateMachineInterface $stateMachine,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function __invoke(ResourceControllerEvent $event): void
    {
        $payment = $event->getSubject();
        if (!$payment instanceof PaymentInterface) {
            return;
        }

        $paymentMethod = $payment->getMethod();
        if (null === $paymentMethod || NmiGatewayFactory::NAME !== $paymentMethod->getGatewayConfig()?->getFactoryName()) {
            return;
        }

        // The controller refuses a transition it cannot apply, after this event; money given back
        // first would then be gone with the payment still saying it was taken.
        if (!$this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND)) {
            return;
        }

        $refusal = $this->refunder->refund($payment, $paymentMethod);
        if (null !== $refusal) {
            // The gateway's sentence is shown as it is; a key is translated through the `flashes`
            // domain into the operator's own language.
            $event->stop($refusal);
        }
    }

    /** The update Sylius's own *Refund* is announced as, and no other. */
    public function onUpdate(ResourceControllerEvent $event): void
    {
        if ($this->appliesARefund()) {
            $this->__invoke($event);
        }
    }

    private function appliesARefund(): bool
    {
        $configuration = $this->requestStack->getCurrentRequest()?->attributes->get('_sylius');
        $stateMachine = is_array($configuration) ? ($configuration['state_machine'] ?? null) : null;

        return is_array($stateMachine) &&
            PaymentTransitions::GRAPH === ($stateMachine['graph'] ?? null) &&
            PaymentTransitions::TRANSITION_REFUND === ($stateMachine['transition'] ?? null);
    }
}
