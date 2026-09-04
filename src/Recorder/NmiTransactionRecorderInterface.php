<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Recorder;

use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Writes down what the gateway did, in the two places it has to live: an indexed row that can
 * be found from the gateway's transaction id alone, and a copy on the payment for the admin UI.
 *
 * Callers persist through this rather than touching the entity, because getting either half
 * wrong is invisible until a webhook arrives for a transaction nothing can resolve.
 */
interface NmiTransactionRecorderInterface
{
    /**
     * Records one gateway transaction against a payment.
     *
     * The row is persisted but not flushed: the payment-request command bus wraps each handler
     * in a Doctrine transaction, so the flush belongs to the handler's own commit.
     *
     * Recording the same operation on the same transaction twice returns the existing row and
     * writes nothing. The gateway's answers are the same answer, and a retry must not double
     * the log.
     *
     * @param string      $type                one of NmiTransactionInterface::TYPES — the operation
     *                                         asked of the gateway, not the response's own `type`,
     *                                         which names the payment method instead
     * @param string|null $parentTransactionId only for a refund: the transaction being refunded,
     *                                         which the gateway never records against itself
     */
    public function record(
        PaymentInterface $payment,
        NmiResponse $response,
        string $type,
        ?string $parentTransactionId = null,
    ): NmiTransactionInterface;

    /**
     * Writes down a refusal, so the operator can read it on the order rather than in a flash
     * message that is gone on the next click. There is no row for this: nothing happened at the
     * gateway, so there is no transaction to record — only a reason worth keeping.
     */
    public function recordRefusal(PaymentInterface $payment, string $messageKey, string $detail): void;
}
