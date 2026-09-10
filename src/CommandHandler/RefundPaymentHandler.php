<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandHandler;

use JpmMartin\SyliusNmiPlugin\Command\RefundPayment;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiExceptionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiClientInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfiguration;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfigurationProviderInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface;
use JpmMartin\SyliusNmiPlugin\Refund\MoneyTakingTransactionProvider;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;

/**
 * Gives the money back, by whichever of the two ways the gateway will still accept.
 *
 * The operator presses one button. Which operation runs is not something they should have to
 * decide.
 *
 * **When the store has been told the transaction settled, it goes straight to the refund.** That
 * record comes from a webhook, and it is the only way this plugin can know: the gateway exposes no
 * settled marker on a transaction and no refundable balance, so there is nothing to read on demand
 * that would answer the question.
 *
 * **Otherwise it asks, exactly as it did before.** A void is tried first, because a void never
 * appears on the cardholder's statement while a refund does. If the transaction has settled the
 * gateway refuses the void in words, and a refund follows. The refusals are unambiguous in both
 * directions — a voided transaction cannot be refunded and a refunded one cannot be voided — so a
 * lost answer cannot move money twice.
 *
 * That fallback is not a leftover. A store that never wires webhooks has no settlement record and
 * must keep working exactly as it did, which is what the second path is for.
 *
 * @internal
 */
final class RefundPaymentHandler
{
    /** The transaction to reverse, newest kind first: a capture supersedes its authorisation. */
    public function __construct(
        private readonly PaymentRequestProviderInterface $paymentRequestProvider,
        private readonly NmiGatewayConfigurationProviderInterface $configurationProvider,
        private readonly NmiClientInterface $client,
        private readonly NmiTransactionRecorderInterface $recorder,
        private readonly MoneyTakingTransactionProvider $transactions,
        private readonly StateMachineInterface $stateMachine,
    ) {
    }

    public function __invoke(RefundPayment $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);

        $payment = $paymentRequest->getPayment();
        if (!$payment instanceof PaymentInterface) {
            throw new \LogicException(sprintf('Expected a core payment, got "%s".', $payment::class));
        }

        $transaction = $this->transactions->forPayment($payment);
        if (null === $transaction || null === $transaction->getTransactionId()) {
            $this->fail($paymentRequest, $payment, 'jpm_martin_sylius_nmi.payment.nothing_to_refund');

            return;
        }

        // What the refund plugin may already have given back is subtracted first, and the gateway
        // is not asked in order to find that out — the store's own record answers it. Nothing
        // left means nothing is asked.
        $returned = $this->transactions->returnedAgainst($transaction);
        $remaining = $this->transactions->remainingOn($transaction);
        if (0 === $remaining) {
            $this->fail($paymentRequest, $payment, 'jpm_martin_sylius_nmi.payment.already_refunded');

            return;
        }

        $transactionId = $transaction->getTransactionId();

        try {
            $configuration = $this->configurationProvider->fromPaymentMethod($paymentRequest->getMethod());
            [$response, $type, $parent] = $this->giveBack(
                $configuration,
                $payment,
                $transactionId,
                null !== $transaction->getSettledAt(),
                // The whole transaction when nothing has gone back yet — the gateway's own
                // meaning of a refund without an amount — and the remainder otherwise.
                0 === $returned ? null : $remaining,
            );
        } catch (NmiTransportException) {
            // The void that went unanswered lands here. Whether the money moved is unknown, and
            // the operator is told that rather than told it was refused.
            $this->fail($paymentRequest, $payment, 'jpm_martin_sylius_nmi.payment.unreachable');

            return;
        } catch (NmiExceptionInterface $exception) {
            $this->fail($paymentRequest, $payment, 'jpm_martin_sylius_nmi.payment.refund_refused', $this->reasonFrom($exception));

            return;
        }

        // Which of the two ran is recorded, because from the outside the difference is invisible
        // and the operator will be asked about it later.
        $this->recorder->record($payment, $response, $type, $parent);

        $paymentRequest->setResponseData([
            'operation' => $type,
            'transaction_id' => $response->transactionId,
            'status' => $response->status,
            'response_code' => $response->responseCode,
            'response_text' => $response->responseText,
        ]);

        $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);
    }

    /**
     * Tries the cheaper reversal first and falls back to the other when the gateway says it is too
     * late — unless the store already knows it is too late, in which case the void is not
     * attempted at all.
     *
     * A transport failure is deliberately *not* caught here: it is not a refusal, the void may
     * well have gone through, and asking again is how money leaves twice.
     *
     * @return array{NmiResponse, string, string|null}
     */
    private function giveBack(NmiGatewayConfiguration $configuration, PaymentInterface $payment, string $transactionId, bool $hasSettled, ?int $amount): array
    {
        // A partial refund is only ever recorded against a settled transaction, and a void is
        // the whole transaction or nothing: money already partly returned rules the void out.
        if ($hasSettled || null !== $amount) {
            // No void, and no request spent finding out it would be refused. The store was told.
            return [
                $this->client->refund($configuration, $transactionId, $amount, (string) $payment->getCurrencyCode()),
                NmiTransactionInterface::TYPE_REFUND,
                $transactionId,
            ];
        }

        try {
            return [
                $this->client->void($configuration, $transactionId),
                NmiTransactionInterface::TYPE_VOID,
                null,
            ];
        } catch (NmiTransportException $exception) {
            throw $exception;
        } catch (NmiGatewayException) {
            return [
                $this->client->refund($configuration, $transactionId, null, (string) $payment->getCurrencyCode()),
                NmiTransactionInterface::TYPE_REFUND,
                $transactionId,
            ];
        }
    }

    private function reasonFrom(NmiExceptionInterface $exception): ?string
    {
        $message = $exception instanceof NmiGatewayException ? $exception->getGatewayMessage() : null;

        return null !== $message && '' !== $message ? $message : null;
    }

    private function fail(PaymentRequestInterface $paymentRequest, PaymentInterface $payment, string $messageKey, ?string $detail = null): void
    {
        $this->recorder->recordRefusal($payment, $messageKey, $detail);

        $paymentRequest->setResponseData(['message_key' => $messageKey, 'detail' => $detail]);

        $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);
    }
}
