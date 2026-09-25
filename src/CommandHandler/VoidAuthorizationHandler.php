<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandHandler;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Command\VoidAuthorization;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\EventListener\VoidAuthorizationsOfCancelledPaymentsListener;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiExceptionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiClientInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfiguration;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfigurationProviderInterface;
use JpmMartin\SyliusNmiPlugin\Lifecycle\NmiOpenAuthorizationResolver;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;

/**
 * Voids the authorisation a cancelled payment left open, and records what the gateway said.
 *
 * The cancellation has already been saved, and nothing here can undo it: the payment state machine
 * offers nothing out of cancelled. So a refusal is recorded on the payment and not tried again —
 * it is the gateway's answer, not a failure to reach it — and the authorisation is left to expire,
 * which is what it would have done had this never run. Only a gateway that did not answer is worth
 * asking again.
 *
 * **Never a write from inside the flush that saved the cancellation.** That is where this runs when
 * the store has made its queue synchronous, and a flush from there would re-enter one that has not
 * finished. It then sends the void, writes nothing, and says so.
 *
 * @internal
 */
final class VoidAuthorizationHandler
{
    public const REFUSED = 'jpm_martin_sylius_nmi.payment.cancelled_authorization_void_refused';

    /** @param PaymentRepositoryInterface<PaymentInterface> $payments */
    public function __construct(
        private readonly PaymentRepositoryInterface $payments,
        private readonly NmiOpenAuthorizationResolver $openAuthorizations,
        private readonly NmiGatewayConfigurationProviderInterface $configurationProvider,
        private readonly NmiClientInterface $client,
        private readonly NmiTransactionRecorderInterface $recorder,
        private readonly EntityManagerInterface $manager,
        private readonly LoggerInterface $logger,
        private readonly VoidAuthorizationsOfCancelledPaymentsListener $cancellations,
    ) {
    }

    public function __invoke(VoidAuthorization $command): void
    {
        $payment = $this->payments->find($command->paymentId);
        if (!$payment instanceof PaymentInterface) {
            // Without the payment there is no method to void with, and no retry will bring it back.
            $this->logger->warning('Cannot void the authorisation of a cancelled NMI payment: the payment no longer exists.', [
                'payment_id' => $command->paymentId,
            ]);

            return;
        }

        // Never a live order's authorisation. With the default queue this cannot happen — the message
        // is written in the same transaction as the cancellation — but on a queue outside the
        // database it can arrive before that transaction commits, or after it was rolled back. So the
        // transport is asked to try again, and a cancellation that never comes is never voided.
        if (PaymentInterface::STATE_CANCELLED !== $payment->getState()) {
            throw new \RuntimeException(sprintf(
                'NMI payment %d is "%s", not cancelled, so its authorisation is left alone.',
                $command->paymentId,
                (string) $payment->getState(),
            ));
        }

        // Read now rather than when the message was sent: a void already recorded, by this handler
        // on an earlier attempt or by the payment's own void, is not sent again.
        $transactionId = $this->openAuthorizations->resolve($payment)?->getTransactionId();
        if (null === $transactionId) {
            return;
        }

        $configuration = $this->configurationOf($payment);
        if (null === $configuration) {
            return;
        }

        if ($this->cancellations->isDispatching()) {
            $this->voidWithoutRecording($configuration, $payment, $transactionId);

            return;
        }

        try {
            $response = $this->client->void($configuration, $transactionId);
        } catch (NmiTransportException $exception) {
            // The gateway never answered, so the void may or may not have been taken. Asking again is
            // right either way, and whatever the gateway answers the second time is what is recorded.
            throw $exception;
        } catch (NmiExceptionInterface $exception) {
            $detail = $this->reasonFrom($exception);
            $this->recorder->recordRefusal($payment, self::REFUSED, $detail);
            $this->manager->flush();

            $this->logger->warning('NMI refused to void the authorisation of a cancelled payment; it stays open until it expires.', [
                'payment_id' => $command->paymentId,
                'transaction_id' => $transactionId,
                'reason' => $detail,
            ]);

            return;
        }

        $this->recorder->record($payment, $response, NmiTransactionInterface::TYPE_VOID);
        $this->manager->flush();
    }

    private function configurationOf(PaymentInterface $payment): ?NmiGatewayConfiguration
    {
        $method = $payment->getMethod();

        try {
            if (!$method instanceof PaymentMethodInterface) {
                throw NmiGatewayException::configuration('The payment has no payment method.');
            }

            return $this->configurationProvider->fromPaymentMethod($method);
        } catch (NmiGatewayException|\InvalidArgumentException $exception) {
            // A configuration that is missing or incomplete does not improve by being asked again in
            // a second, so this is recorded rather than retried — as a purge does in the same case.
            $this->logger->warning('Cannot void the authorisation of a cancelled NMI payment: its payment method is not usable.', [
                'payment_id' => $payment->getId(),
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The void, from inside the flush that saved the cancellation — which only a synchronous queue
     * brings about. Nothing is written, and nothing is thrown: the cancellation has been committed,
     * or is about to be by the transaction around it, and failing here would fail the request or
     * roll that transaction back, cancellation and all.
     */
    private function voidWithoutRecording(NmiGatewayConfiguration $configuration, PaymentInterface $payment, string $transactionId): void
    {
        $context = ['payment_id' => $payment->getId(), 'transaction_id' => $transactionId];

        try {
            $this->client->void($configuration, $transactionId);
        } catch (NmiExceptionInterface $exception) {
            $this->logger->warning('Could not void the authorisation of a cancelled NMI payment, and could not record why: the queue that carries the void is synchronous.', $context + [
                'reason' => $this->reasonFrom($exception),
            ]);

            return;
        }

        $this->logger->warning('Voided the authorisation of a cancelled NMI payment without recording it: the queue that carries the void is synchronous. Make it asynchronous for voids to be recorded.', $context);
    }

    /** The gateway's own sentence when it gave one, which is the only description some refusals have. */
    private function reasonFrom(NmiExceptionInterface $exception): string
    {
        $message = $exception instanceof NmiGatewayException ? $exception->getGatewayMessage() : null;

        return null !== $message && '' !== $message ? $message : $exception->getMessage();
    }
}
