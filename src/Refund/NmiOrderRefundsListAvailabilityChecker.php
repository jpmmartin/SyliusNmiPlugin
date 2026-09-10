<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Refund;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\RefundPlugin\Checker\OrderRefundingAvailabilityCheckerInterface;

/**
 * Keeps the refund plugin's refunds page off an order whose NMI payment has been refunded.
 *
 * The refund plugin shows that page for a fully refunded order too, as a history, and its
 * template reads the method off the order's last *completed* payment without asking whether
 * there is one. With an offline refund there always is, because nothing moves that payment; with
 * an NMI refund there is not, because the payment is moved to refunded once the money is back —
 * and the page fails with an error instead of rendering. So, as the Adyen plugin does for its
 * own payments, the page is declared unavailable for that order: the refund plugin answers with
 * its own sentence and sends the operator back, and the order page still lists every refund
 * payment and credit memo. Orders paid any other way get the inner answer.
 */
final class NmiOrderRefundsListAvailabilityChecker implements OrderRefundingAvailabilityCheckerInterface
{
    /** @param OrderRepositoryInterface<OrderInterface> $orderRepository */
    public function __construct(
        private readonly OrderRefundingAvailabilityCheckerInterface $inner,
        private readonly OrderRepositoryInterface $orderRepository,
    ) {
    }

    public function __invoke(string $orderNumber): bool
    {
        $order = $this->orderRepository->findOneByNumber($orderNumber);
        $payment = $order instanceof OrderInterface ? $order->getLastPayment() : null;

        if (
            $payment instanceof PaymentInterface &&
            NmiPaymentMethods::includes($payment->getMethod()) &&
            PaymentInterface::STATE_REFUNDED === $payment->getState()
        ) {
            return false;
        }

        return $this->inner->__invoke($orderNumber);
    }
}
