<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Lifecycle;

use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * The authorisation of a payment that is still open at the gateway, as far as the store's record
 * knows: the newest one, with neither a capture nor a void recorded under its identifier.
 *
 * **The newest, because a declined attempt is on record too.** Checkout records the gateway's answer
 * whether it approved or declined, under the same type, so a payment authorised on the second try
 * has two authorisations on record and only the later one can still be open. That also means an
 * authorisation on record does not prove the gateway approved it: whether the payment ever reached
 * `authorized` is the caller's question, not this one's.
 *
 * A capture and a void keep their authorisation's identifier, which is how they are found here.
 *
 * @internal
 */
final class NmiOpenAuthorizationResolver
{
    /** What closes an authorisation for good: claiming the money, or letting it go. */
    private const CLOSING_TYPES = [
        NmiTransactionInterface::TYPE_CAPTURE,
        NmiTransactionInterface::TYPE_VOID,
    ];

    public function __construct(
        private readonly NmiTransactionRepositoryInterface $transactions,
    ) {
    }

    public function resolve(PaymentInterface $payment): ?NmiTransactionInterface
    {
        $authorization = $this->transactions->findLatestForPayment($payment, NmiTransactionInterface::TYPE_AUTH);
        $transactionId = $authorization?->getTransactionId();
        if (null === $transactionId) {
            return null;
        }

        foreach (self::CLOSING_TYPES as $type) {
            if (null !== $this->transactions->findOneByTransactionIdAndType($transactionId, $type)) {
                return null;
            }
        }

        return $authorization;
    }
}
