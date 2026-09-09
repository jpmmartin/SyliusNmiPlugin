<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Webhook;

use JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface;
use Psr\Log\LoggerInterface;
use Sylius\Bundle\PaymentBundle\Announcer\PaymentRequestAnnouncerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Factory\PaymentRequestFactoryInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;

/**
 * Decides what a verified, recorded event is about, and hands it on.
 *
 * **The audit trail is the reason this exists in this shape.** A transaction event could be applied
 * to the payment from the endpoint directly, and nothing would look different on the order — except
 * that the store would hold no record of having been told. So a payment-related event becomes a
 * payment request with the notify action and is announced, exactly as the framework's own notify
 * action would: an operator then sees what the store asked the gateway and what the gateway told
 * the store in one list, told apart by the action.
 *
 * Settlement, chargebacks and card-updater summaries do not travel this way, because none of them
 * is about one payment. They arrive as the change proceeds.
 */
final class NmiWebhookRouter implements NmiWebhookRouterInterface
{
    /** The prefix of every event this router currently acts on. */
    private const TRANSACTION_PREFIX = 'transaction.';

    /**
     * @param PaymentRequestFactoryInterface<PaymentRequestInterface> $paymentRequestFactory
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository
     */
    public function __construct(
        private readonly NmiTransactionRepositoryInterface $transactionRepository,
        private readonly PaymentRequestFactoryInterface $paymentRequestFactory,
        private readonly PaymentRequestRepositoryInterface $paymentRequestRepository,
        private readonly PaymentRequestAnnouncerInterface $announcer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function route(NmiWebhookEnvelope $envelope, PaymentMethodInterface $paymentMethod): void
    {
        if (!str_starts_with($envelope->eventType, self::TRANSACTION_PREFIX)) {
            $this->logger->info('Recorded an NMI webhook delivery this store does not act on.', [
                'event_id' => $envelope->eventId,
                'event_type' => $envelope->eventType,
            ]);

            return;
        }

        $payment = $this->paymentFor($envelope);
        if (null === $payment) {
            // *An event for a transaction this store does not know.* Normal rather than
            // exceptional: a gateway account shared with another store produces these
            // continuously. Logged, nothing created, and the delivery is still a success so the
            // gateway stops.
            $this->logger->info('An NMI webhook named a transaction this store does not know.', [
                'event_id' => $envelope->eventId,
                'event_type' => $envelope->eventType,
                'transaction_id' => self::transactionIdOf($envelope),
            ]);

            return;
        }

        $paymentRequest = $this->paymentRequestFactory->create($payment, $paymentMethod);
        $paymentRequest->setAction(PaymentRequestInterface::ACTION_NOTIFY);
        $paymentRequest->setPayload([
            'event_type' => $envelope->eventType,
            'event_body' => $envelope->eventBody,
        ]);
        $this->paymentRequestRepository->add($paymentRequest);

        $this->announcer->dispatchPaymentRequestCommand($paymentRequest);
    }

    /**
     * The payment an event is about, found through the store's own transaction log.
     *
     * The log is what makes this possible at all: the gateway names a transaction, and only the
     * store knows which of its payments that transaction belonged to. Both the identifier and the
     * one a refund was recorded against are searched, because an inbound event does not say which
     * of the two it is naming.
     */
    private function paymentFor(NmiWebhookEnvelope $envelope): ?PaymentInterface
    {
        $transactionId = self::transactionIdOf($envelope);
        if (null === $transactionId) {
            return null;
        }

        $payment = $this->transactionRepository->findOneByAnyTransactionId($transactionId)?->getPayment();

        return $payment instanceof PaymentInterface ? $payment : null;
    }

    private static function transactionIdOf(NmiWebhookEnvelope $envelope): ?string
    {
        $transactionId = $envelope->eventBody['transaction_id'] ?? null;

        if (!is_scalar($transactionId)) {
            return null;
        }

        $transactionId = trim((string) $transactionId);

        return '' === $transactionId ? null : $transactionId;
    }
}
