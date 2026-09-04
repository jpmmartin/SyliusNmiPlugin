<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Recorder;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiAmountFormatter;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Resource\Factory\FactoryInterface;

final class NmiTransactionRecorder implements NmiTransactionRecorderInterface
{
    /** The key this plugin owns inside the payment's details; nothing else is touched. */
    public const DETAILS_KEY = 'nmi';

    /** Where the last refusal is kept, beside the last transaction rather than replacing it. */
    public const REFUSAL_DETAILS_KEY = 'nmi_refusal';

    /** @param FactoryInterface<NmiTransactionInterface> $transactionFactory */
    public function __construct(
        private readonly FactoryInterface $transactionFactory,
        private readonly NmiTransactionRepositoryInterface $transactionRepository,
        private readonly ObjectManager $manager,
        private readonly NmiAmountFormatter $amountFormatter,
    ) {
    }

    public function record(
        PaymentInterface $payment,
        NmiResponse $response,
        string $type,
        ?string $parentTransactionId = null,
    ): NmiTransactionInterface {
        if (!in_array($type, NmiTransactionInterface::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an operation this plugin records.', $type));
        }

        $transactionId = $response->transactionId;
        if (null === $transactionId) {
            // Without an id the row could never be found again, which is the only reason it
            // exists. Storing it anyway would hide the failure until a webhook arrived.
            throw new \InvalidArgumentException('The gateway returned no transaction id, so there is nothing to record.');
        }

        $currencyCode = strtoupper($response->currency ?? (string) $payment->getCurrencyCode());
        $amount = null === $response->amount
            ? (int) $payment->getAmount()
            : $this->amountFormatter->parse($response->amount, $currencyCode);

        $transaction = $this->transactionRepository->findOneByTransactionIdAndType($transactionId, $type);
        $isNew = null === $transaction;

        if (null === $transaction) {
            $transaction = $this->transactionFactory->createNew();
            $transaction->setTransactionId($transactionId);
            $transaction->setType($type);
            $transaction->setParentTransactionId($parentTransactionId);
            $transaction->setPayment($payment);
            $transaction->setAmount($amount);
            $transaction->setCurrencyCode($currencyCode);
            $transaction->setAuthCode($response->authCode);
        }

        // The copy on the payment is written either way: a replayed answer still belongs on the
        // payment, and the admin UI reads only this.
        $this->denormaliseOntoPayment($payment, $response, $type, $transactionId, $amount, $currencyCode);

        if ($isNew) {
            $this->manager->persist($transaction);
        }

        return $transaction;
    }

    public function recordRefusal(PaymentInterface $payment, string $messageKey, ?string $detail = null): void
    {
        $details = $payment->getDetails();

        $details[self::REFUSAL_DETAILS_KEY] = [
            'message_key' => $messageKey,
            'detail' => $detail,
            'recorded_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];

        $payment->setDetails($details);
    }

    private function denormaliseOntoPayment(
        PaymentInterface $payment,
        NmiResponse $response,
        string $type,
        string $transactionId,
        int $amount,
        string $currencyCode,
    ): void {
        $details = $payment->getDetails();

        // The latest operation only. The table is the history; this is what the admin screen
        // shows, and a growing list in a serialised column is not something to maintain twice.
        $details[self::DETAILS_KEY] = [
            'transaction_id' => $transactionId,
            'type' => $type,
            'status' => $response->status,
            'response_code' => $response->responseCode,
            'response_text' => $response->responseText,
            'auth_code' => $response->authCode,
            'amount' => $amount,
            'currency_code' => $currencyCode,
            'recorded_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];

        $payment->setDetails($details);
    }
}
