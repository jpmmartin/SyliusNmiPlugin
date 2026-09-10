<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Refund;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\RefundPlugin\Provider\RefundPaymentMethodsProviderInterface;

/**
 * Which methods the refund plugin offers for giving an order's money back, as far as NMI is
 * concerned: the one that took it, and no other of this gateway's.
 *
 * Money can only go back through the account that took it, so an order paid with an NMI method
 * is offered that method — as soon as the transaction that took the money is on record — and
 * never a second NMI method that happens to be enabled on the channel. Whether the gateway will
 * refund before settling is the gateway's to say, and it says it when asked. Everything
 * the inner provider offers for other gateways is passed through untouched, which is what keeps
 * the store's own list the store's own. An NMI entry a store may have put on that list adds
 * nothing: whatever it would add is filtered out here.
 */
final class NmiRefundPaymentMethodsProvider implements RefundPaymentMethodsProviderInterface
{
    public function __construct(
        private readonly RefundPaymentMethodsProviderInterface $inner,
        private readonly MoneyTakingTransactionProvider $transactions,
    ) {
    }

    public function findForOrder(OrderInterface $order): array
    {
        $others = array_values(array_filter(
            $this->inner->findForOrder($order),
            static fn (PaymentMethodInterface $method): bool => !NmiPaymentMethods::includes($method),
        ));

        $payment = $order->getLastPayment(PaymentInterface::STATE_COMPLETED);
        $method = $payment?->getMethod();

        if (
            !$payment instanceof PaymentInterface ||
            !$method instanceof PaymentMethodInterface ||
            !NmiPaymentMethods::includes($method) ||
            !$method->isEnabled() ||
            null === $this->transactions->forPayment($payment)
        ) {
            return $others;
        }

        return [$method, ...$others];
    }
}
