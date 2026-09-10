<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Webhook;

use JpmMartin\SyliusNmiPlugin\Entity\NmiGatewayNoticeInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiAmountFormatter;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfigurationProviderInterface;
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
 *
 * @internal
 */
final class NmiWebhookRouter implements NmiWebhookRouterInterface
{
    /** The prefix of the events that are about one payment. */
    private const TRANSACTION_PREFIX = 'transaction.';

    /** A batch that reached the processor, naming every transaction it carried. */
    private const SETTLEMENT_COMPLETE = 'settlement.batch.complete';

    /** A batch that did not, naming **no transaction at all**. */
    private const SETTLEMENT_FAILURE = 'settlement.batch.failure';

    /** Money taken back by cardholders' issuers, reported in batches like everything else. */
    private const CHARGEBACK_COMPLETE = 'chargeback.batch.complete';

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
        private readonly NmiAmountFormatter $amountFormatter,
        private readonly NmiGatewayConfigurationProviderInterface $configurationProvider,
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

        if (self::CHARGEBACK_COMPLETE === $envelope->eventType) {
            $this->recordChargebacks($envelope, $paymentMethod);

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
            $this->recordUnknownTransaction($envelope, $paymentMethod);

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
     * *An event for a transaction this store does not know.*
     *
     * **Normal rather than exceptional.** A gateway account shared with another shop or another
     * system delivers that party's every sale, refund and void here too, and none of them is this
     * store's to act on. So it is logged, nothing is created, and the delivery is still answered
     * with success — anything else and the gateway would redeliver each of them twenty times over
     * three days.
     *
     * An operator may ask to be told anyway, and only then is a notice written. The setting is off
     * by default because on a shared account it would fill the page with the other party's
     * business; it exists for the store that has the account to itself, where an unknown
     * transaction means something is wrong.
     */
    private function recordUnknownTransaction(NmiWebhookEnvelope $envelope, PaymentMethodInterface $paymentMethod): void
    {
        $transactionId = self::transactionIdOf($envelope);

        $this->logger->info('An NMI webhook named a transaction this store does not know.', [
            'event_id' => $envelope->eventId,
            'event_type' => $envelope->eventType,
            'transaction_id' => $transactionId,
        ]);

        if (!$this->configurationProvider->fromPaymentMethod($paymentMethod)->notifyUnknownTransactions) {
            return;
        }

        $this->noticeRecorder->record(
            NmiGatewayNoticeInterface::TYPE_UNKNOWN_TRANSACTION,
            $transactionId,
            (string) $paymentMethod->getCode(),
            null,
            null,
            null,
            $envelope->eventType,
        );
    }

    /**
     * Records every chargeback in a batch, each on its own.
     *
     * **One entry that resolves to nothing must not cost the others.** A batch names the
     * chargebacks the processor reported for the whole gateway account, and on an account shared
     * with another store most of them are not this store's — so an unrecognised entry is the
     * ordinary case and is recorded all the same, unattached, rather than dropped or allowed to
     * fail the batch.
     *
     * **How an entry is matched to an order rests on an assumption, stated here because it is
     * one.** The entry carries `id`, `date`, `customer_name`, `cc_number`, `amount` and `reason`,
     * and the gateway's documentation never says what `id` identifies — the chargeback, or the
     * transaction charged back. Every identifier in its samples has the ten-digit shape of a
     * transaction id, which is suggestive and is not evidence. It is resolved on that assumption
     * and a miss simply leaves the notice unattached: if the assumption is wrong, every chargeback
     * is recorded and visible with no order beside it, which is the safe direction. Nothing is
     * ever attached to an order it does not belong to.
     */
    private function recordChargebacks(NmiWebhookEnvelope $envelope, PaymentMethodInterface $paymentMethod): void
    {
        $entries = $envelope->eventBody['chargebacks'] ?? null;
        if (!is_array($entries)) {
            $this->logger->warning('An NMI chargeback batch carried no chargebacks.', [
                'event_id' => $envelope->eventId,
            ]);

            return;
        }

        $recorded = 0;
        $attached = 0;

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $reference = self::textIn($entry, 'id');
            $payment = null === $reference ? null : $this->transactionRepository->findOneByAnyTransactionId($reference)?->getPayment();
            $payment = $payment instanceof PaymentInterface ? $payment : null;

            [$amount, $currencyCode, $reason] = $this->moneyAndReason($entry, $payment);

            if ($this->noticeRecorder->record(
                NmiGatewayNoticeInterface::TYPE_CHARGEBACK,
                $reference,
                (string) $paymentMethod->getCode(),
                $payment,
                $amount,
                $currencyCode,
                $reason,
            )) {
                ++$recorded;
            }

            if (null !== $payment) {
                ++$attached;
            }
        }

        // Error level, and no setting to quieten it. A chargeback is money taken back.
        $this->logger->error('Recorded a batch of NMI chargebacks.', [
            'event_id' => $envelope->eventId,
            'recorded' => $recorded,
            'attached_to_an_order' => $attached,
        ]);
    }

    /**
     * The amount in the smallest unit, its currency, and the reason an operator reads.
     *
     * **A chargeback entry names an amount but never a currency**, and converting a decimal
     * without one guesses at how many places it has — right for dollars, wrong for yen. So the
     * amount becomes a number only when a resolved order supplies the currency; otherwise it stays
     * in the reason exactly as the gateway wrote it, which is worse to sort by and impossible to
     * be wrong about.
     *
     * @param array<string, mixed> $entry
     *
     * @return array{int|null, string|null, string|null}
     */
    private function moneyAndReason(array $entry, ?PaymentInterface $payment): array
    {
        $reason = self::textIn($entry, 'reason');
        $rawAmount = self::textIn($entry, 'amount');
        $currencyCode = $payment?->getCurrencyCode();

        if (null === $rawAmount) {
            return [null, null, $reason];
        }

        if (null === $currencyCode) {
            return [null, null, trim(sprintf('%s (%s)', $reason ?? '', $rawAmount))];
        }

        try {
            return [$this->amountFormatter->parse($rawAmount, $currencyCode), $currencyCode, $reason];
        } catch (\Throwable) {
            // An amount this cannot read is not a reason to lose the chargeback.
            return [null, null, trim(sprintf('%s (%s)', $reason ?? '', $rawAmount))];
        }
    }

    /** @param array<string, mixed> $entry */
    private static function textIn(array $entry, string $key): ?string
    {
        $value = $entry[$key] ?? null;

        return is_scalar($value) && '' !== trim((string) $value) ? trim((string) $value) : null;
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
