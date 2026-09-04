<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandHandler;

use JpmMartin\SyliusNmiPlugin\Command\CancelPayment;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiExceptionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiClientInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfigurationProviderInterface;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;

/**
 * Cancels a transaction the gateway has not settled yet.
 *
 * This is the plugin's void. The payment state machine has no such transition — four definitions
 * ship and the two that load omit it — so a void travels as a cancel, and the gateway is asked
 * before the cancel is allowed to happen.
 */
final class CancelPaymentHandler
{
    /** The transaction a void acts on, newest kind first: a capture supersedes its authorisation. */
    private const VOIDABLE_TYPES = [
        NmiTransactionInterface::TYPE_CAPTURE,
        NmiTransactionInterface::TYPE_SALE,
        NmiTransactionInterface::TYPE_AUTH,
    ];

    public function __construct(
        private readonly PaymentRequestProviderInterface $paymentRequestProvider,
        private readonly NmiGatewayConfigurationProviderInterface $configurationProvider,
        private readonly NmiClientInterface $client,
        private readonly NmiTransactionRecorderInterface $recorder,
        private readonly NmiTransactionRepositoryInterface $transactionRepository,
        private readonly StateMachineInterface $stateMachine,
    ) {
    }

    public function __invoke(CancelPayment $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);

        $payment = $paymentRequest->getPayment();
        if (!$payment instanceof PaymentInterface) {
            throw new \LogicException(sprintf('Expected a core payment, got "%s".', $payment::class));
        }

        $transaction = $this->voidable($payment);
        if (null === $transaction || null === $transaction->getTransactionId()) {
            // Nothing reached the gateway for this payment, so there is nothing to take back and
            // the cancel is the store's own business. Letting it through is right.
            $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);

            return;
        }

        try {
            $response = $this->client->void(
                $this->configurationProvider->fromPaymentMethod($paymentRequest->getMethod()),
                $transaction->getTransactionId(),
            );
        } catch (NmiTransportException) {
            // The gateway never answered, so the void may or may not have been taken. That is
            // not a refusal and is not told as one.
            $this->fail($paymentRequest, $payment, 'jpm_martin_sylius_nmi.payment.unreachable');

            return;
        } catch (NmiExceptionInterface $exception) {
            // A transaction the gateway has already settled cannot be voided, and it says so in
            // words rather than a code. That refusal is the operator's answer.
            $this->fail($paymentRequest, $payment, 'jpm_martin_sylius_nmi.payment.void_refused', $this->reasonFrom($exception));

            return;
        }

        $this->recorder->record($payment, $response, NmiTransactionInterface::TYPE_VOID);

        $paymentRequest->setResponseData([
            'transaction_id' => $response->transactionId,
            'status' => $response->status,
            'response_code' => $response->responseCode,
            'response_text' => $response->responseText,
        ]);

        $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);
    }

    private function voidable(PaymentInterface $payment): ?NmiTransactionInterface
    {
        foreach (self::VOIDABLE_TYPES as $type) {
            $transaction = $this->transactionRepository->findLatestForPayment($payment, $type);
            if (null !== $transaction) {
                return $transaction;
            }
        }

        return null;
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
