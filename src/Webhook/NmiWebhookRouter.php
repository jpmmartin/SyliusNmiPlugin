<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Webhook;

use JpmMartin\SyliusNmiPlugin\Entity\NmiGatewayNoticeInterface;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiGatewayNoticeRecorderInterface;
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
    /** The prefix of the events that are about one payment. */
    private const TRANSACTION_PREFIX = 'transaction.';

    /** A batch that reached the processor, naming every transaction it carried. */
    private const SETTLEMENT_COMPLETE = 'settlement.batch.complete';

    /** A batch that did not, naming **no transaction at all**. */
    private const SETTLEMENT_FAILURE = 'settlement.batch.failure';

    /**
     * @param PaymentRequestFactoryInterface<PaymentRequestInterface> $paymentRequestFactory
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository
     */
    public function __construct(
        private readonly NmiTransactionRepositoryInterface $transactionRepository,
        private readonly PaymentRequestFactoryInterface $paymentRequestFactory,
        private readonly PaymentRequestRepositoryInterface $paymentRequestRepository,
        private readonly PaymentRequestAnnouncerInterface $announcer,
        private readonly NmiGatewayNoticeRecorderInterface $noticeRecorder,
        private readonly NmiCardUpdateApplierInterface $cardUpdateApplier,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function route(NmiWebhookEnvelope $envelope, PaymentMethodInterface $paymentMethod): void
    {
        if ($this->cardUpdateApplier->supports($envelope->eventType)) {
            $this->cardUpdateApplier->apply($envelope, $paymentMethod);

            return;
        }

        if (self::SETTLEMENT_COMPLETE === $envelope->eventType) {
            $this->recordSettlement($envelope);

            return;
        }

        if (self::SETTLEMENT_FAILURE === $envelope->eventType) {
            $this->recordSettlementFailure($envelope, $paymentMethod);

            return;
        }

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
     * Writes down that a batch settled, against every transaction in it this store knows.
     *
     * **Settlement is a fact about a transaction, not a state of a payment.** No place is added to
     * the payment state machine and none is wanted: `completed` is what the order payment state
     * resolver and the admin screen key on, and an extra state between completed and settled would
     * risk orders that never reach paid. What the moment buys is one question answered without
     * asking the gateway — whether a reversal must be a refund rather than a void.
     *
     * Most identifiers in a batch belong to other stores on a shared account and match nothing.
     * That is the ordinary case, not a failure.
     */
    private function recordSettlement(NmiWebhookEnvelope $envelope): void
    {
        $transactionIds = self::transactionIdsOf($envelope);
        if ([] === $transactionIds) {
            $this->logger->warning('An NMI settlement event named no transactions.', [
                'event_id' => $envelope->eventId,
            ]);

            return;
        }

        $marked = $this->transactionRepository->markSettled($transactionIds, new \DateTimeImmutable());

        $this->logger->info('Recorded an NMI batch settlement.', [
            'event_id' => $envelope->eventId,
            'named' => count($transactionIds),
            'marked' => $marked,
        ]);
    }

    /**
     * A settlement that failed, which the gateway reports **without naming a single transaction**
     * — the payload carries a batch identifier, the merchant and the processor and nothing else.
     *
     * So it cannot be attributed to an order, and inventing an attribution would be worse than
     * having none. It is recorded against the account and surfaced to the operator there.
     */
    private function recordSettlementFailure(NmiWebhookEnvelope $envelope, PaymentMethodInterface $paymentMethod): void
    {
        $batchId = self::textOf($envelope, 'batch_id');

        $this->noticeRecorder->record(
            NmiGatewayNoticeInterface::TYPE_SETTLEMENT_FAILURE,
            $batchId,
            (string) $paymentMethod->getCode(),
        );

        // Logged as well as recorded, and at error level: money that did not reach the processor
        // is not an informational event, and whoever watches the logs should not have to open the
        // admin to find out.
        $this->logger->error('An NMI batch failed to settle.', [
            'event_id' => $envelope->eventId,
            'batch_id' => $batchId,
            'payment_method_code' => $paymentMethod->getCode(),
        ]);
    }

    /**
     * The transaction identifiers a settled batch names.
     *
     * @return list<string>
     */
    private static function transactionIdsOf(NmiWebhookEnvelope $envelope): array
    {
        $ids = $envelope->eventBody['transaction_ids'] ?? null;
        if (!is_array($ids)) {
            return [];
        }

        $found = [];
        foreach ($ids as $id) {
            if (!is_scalar($id)) {
                continue;
            }

            $id = trim((string) $id);
            if ('' !== $id) {
                $found[] = $id;
            }
        }

        return array_values(array_unique($found));
    }

    private static function textOf(NmiWebhookEnvelope $envelope, string $key): ?string
    {
        $value = $envelope->eventBody[$key] ?? null;

        return is_scalar($value) && '' !== trim((string) $value) ? trim((string) $value) : null;
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
