<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Refund;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\RefundPlugin\Checker\OrderRefundingAvailabilityCheckerInterface;

/**
 * Whether the refund plugin may offer refunding an order at all.
 *
 * For an order whose money an NMI method took, not until the transaction has settled: the
 * gateway refunds only settled transactions, and a credit memo the gateway is about to refuse is
 * worse than a button that is not there. Until then the order screen's void, which is the whole
 * amount by the gateway's own rule, is the way. Orders paid any other way get the inner answer.
 */
final class NmiOrderRefundingAvailabilityChecker implements OrderRefundingAvailabilityCheckerInterface
{
    /** @param OrderRepositoryInterface<OrderInterface> $orderRepository */
    public function __construct(
        private readonly OrderRefundingAvailabilityCheckerInterface $inner,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly MoneyTakingTransactionProvider $transactions,
    ) {
    }

    public function __invoke(string $orderNumber): bool
    {
        $order = $this->orderRepository->findOneByNumber($orderNumber);
        // The repository is typed by the order component; the payments are the core's.
        $payment = $order instanceof OrderInterface ? $order->getLastPayment(PaymentInterface::STATE_COMPLETED) : null;

        if ($payment instanceof PaymentInterface && NmiPaymentMethods::includes($payment->getMethod())) {
            if (null === $this->transactions->forPayment($payment)?->getSettledAt()) {
                return false;
            }
        }

        return $this->inner->__invoke($orderNumber);
    }
}
