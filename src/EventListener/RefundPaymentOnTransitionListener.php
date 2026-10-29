<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\EventListener;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use JpmMartin\SyliusNmiPlugin\Refund\NmiPaymentRefunder;
use JpmMartin\SyliusNmiPlugin\Refund\NmiRefundedPayments;
use Sylius\Bundle\ApiBundle\Exception\StateMachineTransitionFailedException;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Gives the money back before a payment is marked refunded, whoever applies the transition.
 *
 * The order screen asks the gateway from its own event and says no in a flash. The platform's admin
 * API — `PATCH /api/v2/admin/payments/{id}/refund` — and a store's own code apply the transition
 * directly, with none of the order screen's events: without this, they would mark a payment refunded
 * and leave the money with the store.
 *
 * **Never twice.** What already moved the money, or learned that it moved, says so before the
 * transition runs: the order screen's *Refund*, the refund plugin's last refund of a payment, and a
 * refund the gateway reports from its portal. Those pass untouched.
 *
 * **Recording money that went back elsewhere.** A void or a refund the store never heard of — made in
 * the portal on a store without webhooks, or one whose answer never arrived — cannot be asked for again:
 * the gateway refuses it. Code applying the transition with `ALREADY_RETURNED => true` in its context
 * says the money is back, and nothing is asked.
 *
 * **On the transition, not on the guard**, for the reason the completion refusal gives: a guard also
 * answers whether the transition is possible, and something that asks must not move money. The
 * transition runs for an application alone, before the payment's state changes, so a refusal here
 * leaves the payment as it was. The exception is the one the admin API already answers with 422.
 *
 * @internal
 */
final class RefundPaymentOnTransitionListener
{
    /** The context key, published in docs/extending.md, that records a refund without asking NMI. */
    public const ALREADY_RETURNED = 'jpm_martin_sylius_nmi.already_returned';

    public function __construct(
        private readonly NmiPaymentRefunder $refunder,
        private readonly NmiRefundedPayments $refundedPayments,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(TransitionEvent $event): void
    {
        $payment = $event->getSubject();
        if (!$payment instanceof PaymentInterface) {
            return;
        }

        $paymentMethod = $payment->getMethod();
        if (null === $paymentMethod || NmiGatewayFactory::NAME !== $paymentMethod->getGatewayConfig()?->getFactoryName()) {
            return;
        }

        if ($this->refundedPayments->isRefunded($payment) || true === ($event->getContext()[self::ALREADY_RETURNED] ?? null)) {
            return;
        }

        $refusal = $this->refunder->refund($payment, $paymentMethod);
        if (null === $refusal) {
            return;
        }

        throw new StateMachineTransitionFailedException(sprintf(
            'Payment %s was not refunded at the gateway, so it is not marked refunded: %s',
            (string) $payment->getId(),
            $this->translator->trans($refusal, [], 'flashes'),
        ));
    }
}
