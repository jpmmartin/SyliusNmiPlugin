<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandHandler;

use JpmMartin\SyliusNmiPlugin\Command\CapturePayment;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiExceptionInterface;
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
 * Claims money the gateway is already holding.
 *
 * The payment's own state machine is left alone here. Whoever asked for the capture is in the
 * middle of moving the payment to completed, and this only decides whether they may: the request
 * finishes completed when the gateway took it and failed when it refused, and the caller reads
 * that.
 */
final class CapturePaymentHandler
{
    public function __construct(
        private readonly PaymentRequestProviderInterface $paymentRequestProvider,
        private readonly NmiGatewayConfigurationProviderInterface $configurationProvider,
        private readonly NmiClientInterface $client,
        private readonly NmiTransactionRecorderInterface $recorder,
        private readonly NmiTransactionRepositoryInterface $transactionRepository,
        private readonly StateMachineInterface $stateMachine,
    ) {
    }

    public function __invoke(CapturePayment $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);

        $payment = $paymentRequest->getPayment();
        if (!$payment instanceof PaymentInterface) {
            throw new \LogicException(sprintf('Expected a core payment, got "%s".', $payment::class));
        }

        $authorisation = $this->transactionRepository->findLatestForPayment($payment, NmiTransactionInterface::TYPE_AUTH);
        if (null === $authorisation || null === $authorisation->getTransactionId()) {
            // Nothing was ever authorised for this payment, so there is nothing to claim. This is
            // the store's own record being wrong rather than the gateway refusing anything.
            $this->fail($paymentRequest, 'jpm_martin_sylius_nmi.payment.no_authorisation', 'This payment has no recorded authorisation to capture.');

            return;
        }

        try {
            $response = $this->client->capture(
                $this->configurationProvider->fromPaymentMethod($paymentRequest->getMethod()),
                $authorisation->getTransactionId(),
                (int) $payment->getAmount(),
                (string) $payment->getCurrencyCode(),
            );
        } catch (NmiExceptionInterface $exception) {
            // Every refusal reads the same way to an operator, and the gateway's own wording is
            // the only description some of them have — an authorisation it will no longer settle
            // has no code of its own.
            $this->fail($paymentRequest, 'jpm_martin_sylius_nmi.payment.capture_refused', $exception->getMessage());

            return;
        }

        $this->recorder->record($payment, $response, NmiTransactionInterface::TYPE_CAPTURE);

        $paymentRequest->setResponseData([
            'transaction_id' => $response->transactionId,
            'status' => $response->status,
            'response_code' => $response->responseCode,
            'response_text' => $response->responseText,
        ]);

        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );
    }

    private function fail(PaymentRequestInterface $paymentRequest, string $messageKey, string $detail): void
    {
        $paymentRequest->setResponseData([
            'message_key' => $messageKey,
            'detail' => $detail,
        ]);

        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_FAIL,
        );
    }
}
