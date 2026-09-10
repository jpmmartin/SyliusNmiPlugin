<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Refund;

use Sylius\RefundPlugin\Entity\RefundPaymentInterface;
use Symfony\Component\Workflow\Event\GuardEvent;

/**
 * Keeps the refund plugin's *Complete* button off a refund payment whose method is NMI.
 *
 * That button exists for refunds performed outside the system — an offline method, money handed
 * back by other means. A gateway refund is completed by the gateway's approval and by nothing
 * else; a button that let an operator mark it done would record money as returned that the
 * gateway still holds. The admin renders the button only while the transition is allowed, so
 * blocking it here makes the button disappear rather than fail.
 *
 * @internal
 */
final class GuardManualCompletionListener
{
    public function __invoke(GuardEvent $event): void
    {
        $refundPayment = $event->getSubject();

        if ($refundPayment instanceof RefundPaymentInterface && NmiPaymentMethods::includes($refundPayment->getPaymentMethod())) {
            $event->setBlocked(true, 'A refund through NMI is completed by the gateway, not by hand.');
        }
    }
}
