<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Refund;

use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiExceptionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiClientInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfigurationProviderInterface;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface;
use JpmMartin\SyliusNmiPlugin\Refund\Exception\RefundNotPerformed;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Sylius\RefundPlugin\Entity\RefundPaymentInterface;
use Sylius\RefundPlugin\Event\RefundPaymentGenerated;
use Sylius\RefundPlugin\StateResolver\RefundPaymentTransitions as RefundPluginTransitions;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Gives the money back when the refund plugin says so.
 *
 * The refund plugin creates a refund payment, announces it, and considers its part done; whether
 * any money moves is up to whoever handles the announcement. For an NMI method that is this: the
 * requested amount — a part or the whole — is refunded at the gateway against the transaction
 * that took the money, recorded, and only then is the refund payment completed, through the
 * plugin's own transition. The gateway saying no is an exception, and the refund plugin's
 * command transaction undoes the credit memo with it.
 *
 * Runs inside the refund plugin's own request, on the bus it dispatches on, never queued: that is
 * what lets a refusal reach the operator's session and the rollback reach the credit memo.
 */
final class RefundPaymentGeneratedHandler
{
    /** @param PaymentRepositoryInterface<PaymentInterface> $paymentRepository */
    public function __construct(
        private readonly RepositoryInterface $refundPaymentRepository,
        private readonly PaymentRepositoryInterface $paymentRepository,
        private readonly MoneyTakingTransactionProvider $transactions,
        private readonly NmiGatewayConfigurationProviderInterface $configurationProvider,
        private readonly NmiClientInterface $client,
        private readonly NmiTransactionRecorderInterface $recorder,
        private readonly StateMachineInterface $stateMachine,
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(RefundPaymentGenerated $event): void
    {
        $refundPayment = $this->refundPaymentRepository->find($event->id());
        if (!$refundPayment instanceof RefundPaymentInterface || !NmiPaymentMethods::includes($refundPayment->getPaymentMethod())) {
            return;
        }

        $payment = $this->paymentRepository->find($event->paymentId());
        $transaction = $payment instanceof PaymentInterface ? $this->transactions->forPayment($payment) : null;
        $transactionId = $transaction?->getTransactionId();

        // The provider only offered NMI because a transaction was on record, so this is a store
        // whose record changed under it. Refusing is the only honest answer.
        if (!$payment instanceof PaymentInterface || null === $transaction || null === $transactionId) {
            $this->tell('jpm_martin_sylius_nmi.refund.nothing_to_refund');

            throw RefundNotPerformed::nothingToRefund();
        }

        $remaining = $this->transactions->remainingOn($transaction);
        if ($event->amount() > $remaining) {
            $this->tell('jpm_martin_sylius_nmi.refund.exceeds_transaction');

            throw RefundNotPerformed::exceedsWhatWasTaken($event->amount(), $remaining);
        }

        try {
            $configuration = $this->configurationProvider->fromPaymentMethod($refundPayment->getPaymentMethod());
            $response = $this->client->refund($configuration, $transactionId, $event->amount(), $event->currencyCode());
        } catch (NmiTransportException) {
            $this->tell('jpm_martin_sylius_nmi.refund.gateway_unreachable');

            throw RefundNotPerformed::unreachable();
        } catch (NmiExceptionInterface $exception) {
            $reason = $exception instanceof NmiGatewayException ? $exception->getGatewayMessage() : null;
            $this->tell('jpm_martin_sylius_nmi.refund.refused_by_gateway', ['%reason%' => $reason ?? $exception->getMessage()]);

            throw RefundNotPerformed::refused($reason);
        }

        $this->recorder->record($payment, $response, NmiTransactionInterface::TYPE_REFUND, $transactionId);

        $this->stateMachine->apply(
            $refundPayment,
            RefundPluginTransitions::GRAPH,
            RefundPaymentTransitions::TRANSITION_CONFIRM_GATEWAY_REFUND,
        );

        // The whole payment given back through the refund plugin is the same fact the order
        // screen's refund records on the payment itself, so the payment says so too — and stops
        // offering a refund of money that is gone. A part leaves the payment as it is.
        if (
            $event->amount() === (int) $payment->getAmount() &&
            $this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND)
        ) {
            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);
        }
    }

    /**
     * The one channel left for a reason: the refund plugin shows a handler's failure as a generic
     * sentence, and everything persisted is rolled back with the refund. Absent a session — a
     * console, a test without a request — there is nobody to tell, and the exception still says it.
     *
     * @param array<string, string> $parameters
     */
    private function tell(string $messageKey, array $parameters = []): void
    {
        try {
            $session = $this->requestStack->getSession();
        } catch (\LogicException) {
            return;
        }

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', $this->translator->trans($messageKey, $parameters, 'flashes'));
        }
    }
}
