<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandHandler;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\CardOnFile\NmiChargeOutcome;
use JpmMartin\SyliusNmiPlugin\Command\ChargeRecurringCredential;
use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiDeclinedException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiClientInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfigurationProviderInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\RecurringChargeFactory;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiRecurringCredentialRepositoryInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;

/**
 * Charges a recurring credential for a payment, with nobody present.
 *
 * As with a card on file, what makes the charge acceptable is checked here on every attempt rather
 * than trusted from the caller: the payment still waits, the credential has not been let go, it
 * belongs to the payment's method, its card is open and in date, and it can cite its first
 * transaction. Every condition is checked before the gateway is contacted, and a failed one is named.
 *
 * The credential's row is locked first and the payment re-read under the lock, so a second charge of
 * the same payment racing the first waits for it and then finds the payment no longer waiting. A
 * charge never lets the credential go, approved or not: whether the renewals continue is the store's
 * decision.
 *
 * @internal
 */
final class ChargeRecurringCredentialHandler
{
    public const NOT_WAITING = 'jpm_martin_sylius_nmi.payment.recurring_not_waiting';

    public const RELEASED = 'jpm_martin_sylius_nmi.payment.recurring_credential_released';

    public const OTHER_METHOD = 'jpm_martin_sylius_nmi.payment.recurring_credential_other_method';

    public const CLOSED = 'jpm_martin_sylius_nmi.payment.recurring_credential_closed';

    public const EXPIRED = 'jpm_martin_sylius_nmi.payment.recurring_credential_expired';

    public const NO_INITIAL_TRANSACTION = 'jpm_martin_sylius_nmi.payment.recurring_credential_without_initial_transaction';

    public const CHARGED = 'jpm_martin_sylius_nmi.payment.recurring_charged';

    public const DECLINED = 'jpm_martin_sylius_nmi.payment.recurring_charge_declined';

    public const UNKNOWN = 'jpm_martin_sylius_nmi.payment.recurring_charge_unknown';

    /** Payload key: the id of the credential to charge. */
    public const CREDENTIAL = 'credential';

    /** Payload flag: the caller completes the payment itself. Set only by the order screen's path. */
    public const LEAVE_PAYMENT = 'leave_payment';

    public function __construct(
        private readonly PaymentRequestProviderInterface $paymentRequestProvider,
        private readonly NmiGatewayConfigurationProviderInterface $configurationProvider,
        private readonly NmiClientInterface $client,
        private readonly RecurringChargeFactory $charges,
        private readonly NmiRecurringCredentialRepositoryInterface $credentials,
        private readonly NmiTransactionRecorderInterface $recorder,
        private readonly EntityManagerInterface $manager,
        private readonly StateMachineInterface $stateMachine,
    ) {
    }

    public function __invoke(ChargeRecurringCredential $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);

        $payment = $paymentRequest->getPayment();
        if (!$payment instanceof PaymentInterface) {
            throw new \LogicException(sprintf('Expected a core payment, got "%s".', $payment::class));
        }

        /** @var array<string, mixed> $payload */
        $payload = is_array($paymentRequest->getPayload()) ? $paymentRequest->getPayload() : [];
        $credentialId = $payload[self::CREDENTIAL] ?? null;

        // Locked before anything is read, then the payment read again under the lock: what another
        // charge committed a moment ago is what this one has to see.
        $credential = is_int($credentialId) ? $this->credentials->findForUpdate($credentialId) : null;
        $this->manager->refresh($payment);

        $refusal = $this->refusal($payment, $credential);
        if (null !== $refusal || null === $credential) {
            $this->finish($paymentRequest, $refusal ?? NmiChargeOutcome::refused(self::RELEASED));

            return;
        }

        /** @var array<string, mixed> $extra */
        $extra = is_array($payload['extra'] ?? null) ? $payload['extra'] : [];

        try {
            $response = $this->client->sale(
                $this->configurationProvider->fromPaymentMethod($paymentRequest->getMethod()),
                $this->charges->forRecurringCredential($payment, $credential, $extra),
            );
        } catch (NmiDeclinedException $exception) {
            // The gateway gave the attempt an identifier: recorded, so a notification about it
            // resolves here, and the reason written where an operator reads it afterwards.
            $this->recorder->record($payment, $exception->getResponse(), NmiTransactionInterface::TYPE_SALE);
            $this->recorder->recordRefusal($payment, self::DECLINED, $exception->getDeclineReason());
            $this->finish($paymentRequest, NmiChargeOutcome::declined(self::DECLINED, $exception->getDeclineReason(), $exception->getResponse()->transactionId, $exception->getResponse()->responseCode));

            return;
        } catch (NmiGatewayException $exception) {
            // The gateway answered, and the answer was no — a decision, like a decline, rather than
            // silence. Its own wording is the only description some of these have.
            $reason = $exception->getGatewayMessage() ?? $exception->getMessage();
            $this->recorder->recordRefusal($payment, self::DECLINED, $reason);
            $this->finish($paymentRequest, NmiChargeOutcome::declined(self::DECLINED, $reason));

            return;
        } catch (NmiTransportException $exception) {
            // Whether the card was charged is unknown, and saying so is the whole point: a caller
            // told "declined" might retry, and the first attempt may have gone through.
            $this->recorder->recordRefusal($payment, self::UNKNOWN, $exception->getMessage());
            $this->finish($paymentRequest, NmiChargeOutcome::unknown(self::UNKNOWN, $exception->getMessage()));

            return;
        }

        $this->recorder->record($payment, $response, NmiTransactionInterface::TYPE_SALE);

        // From the order screen the operator's own action is in the middle of completing the payment
        // and will apply the transition itself; applying it here too would make that fail. Anywhere
        // else nobody else will, and the payment is completed here.
        if (true !== ($payload[self::LEAVE_PAYMENT] ?? false)) {
            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
        }

        $this->finish($paymentRequest, NmiChargeOutcome::approved((string) $response->transactionId, self::CHARGED));
    }

    /**
     * The first condition that does not hold, in the order the specification lists them — or null
     * when a charge may be attempted.
     */
    private function refusal(PaymentInterface $payment, ?NmiRecurringCredentialInterface $credential): ?NmiChargeOutcome
    {
        // New as well as processing: a renewal is a payment the store creates, and the platform
        // leaves it new; the held payment that opened the credential waits in processing.
        if (!in_array($payment->getState(), [PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING], true)) {
            return NmiChargeOutcome::refused(self::NOT_WAITING);
        }

        // A row that is gone — deleted with its customer — was let go by that deletion.
        if (null === $credential || $credential->isReleased()) {
            return NmiChargeOutcome::refused(self::RELEASED);
        }

        // Compared by identity of the row, not of the object: the method may have been loaded twice.
        if ($credential->getPaymentMethod()?->getId() !== $payment->getMethod()?->getId()) {
            return NmiChargeOutcome::refused(self::OTHER_METHOD);
        }

        if (!$credential->isUsable()) {
            return NmiChargeOutcome::refused(self::CLOSED);
        }

        if ($credential->isExpired()) {
            return NmiChargeOutcome::refused(self::EXPIRED);
        }

        // The gateway would take the charge without it; the card networks expect it on every charge
        // made without the shopper. So the refusal is this plugin's, and nothing downstream would
        // make it.
        if (null === $credential->getInitialTransactionId() || '' === $credential->getInitialTransactionId()) {
            return NmiChargeOutcome::refused(self::NO_INITIAL_TRANSACTION);
        }

        return null;
    }

    private function finish(PaymentRequestInterface $paymentRequest, NmiChargeOutcome $outcome): void
    {
        $paymentRequest->setResponseData($outcome->toArray());

        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            $outcome->isApproved() ? PaymentRequestTransitions::TRANSITION_COMPLETE : PaymentRequestTransitions::TRANSITION_FAIL,
        );
    }
}
