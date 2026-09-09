<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandHandler;

use JpmMartin\SyliusNmiPlugin\Command\NotifyPayment;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;

/**
 * Reflects onto a payment what the gateway did to it somewhere else.
 *
 * **Nothing here initiates anything.** The money has already moved — in the gateway's own portal,
 * or by another integration on the same account — and this handler's whole job is to make the
 * store's record agree with a fact it did not cause. That is why it never calls the gateway: there
 * is nothing left to ask for.
 *
 * **Applying the transition programmatically does not re-enter the plugin.** The listeners that
 * void and refund at the gateway hang off `sylius.payment.pre_cancel` and `sylius.payment.pre_refund`,
 * which are *resource* events — checked against the installed source, they are dispatched only by
 * `ResourceController`, never by `StateMachineInterface::apply()`. Were it otherwise, reflecting a
 * refund performed in the portal would ask the gateway to refund again, and the store would give
 * the money back twice. Nothing else in this plugin depends on that distinction, so it is written
 * down here.
 */
final class NotifyPaymentHandler
{
    /**
     * Which payment transition an event asks for, by the operation it reports.
     *
     * Only two, and deliberately: a refund and a void are the operations an operator performs in
     * the gateway's portal against a payment this store already knows. A sale or an authorisation
     * performed there belongs to no order here and takes the unknown-transaction path instead.
     */
    private const TRANSITIONS = [
        'refund' => PaymentTransitions::TRANSITION_REFUND,
        'void' => PaymentTransitions::TRANSITION_CANCEL,
    ];

    private const OUTCOME_SUCCESS = 'success';

    private const OUTCOME_FAILURE = 'failure';

    /** What a failure event is recorded as, so an operator reads it on the order. */
    public const FAILURE_MESSAGE_KEY = 'jpm_martin_sylius_nmi.payment.gateway_reported_failure';

    public function __construct(
        private readonly PaymentRequestProviderInterface $paymentRequestProvider,
        private readonly StateMachineInterface $stateMachine,
        private readonly NmiTransactionRecorderInterface $recorder,
    ) {
    }

    public function __invoke(NotifyPayment $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);

        $payment = $paymentRequest->getPayment();
        if (!$payment instanceof PaymentInterface) {
            throw new \LogicException(sprintf('Expected a core payment, got "%s".', $payment::class));
        }

        $payload = $paymentRequest->getPayload() ?? [];
        $eventType = is_string($payload['event_type'] ?? null) ? $payload['event_type'] : '';
        [$operation, $outcome] = self::readEventType($eventType);

        if (self::OUTCOME_FAILURE === $outcome) {
            // *A failure event.* The reason is written where the operator reads it and **no state
            // is invented**: an operation that failed at the gateway changed nothing, so a payment
            // that moves here would be the store telling itself something untrue.
            $this->recorder->recordRefusal($payment, self::FAILURE_MESSAGE_KEY, self::reasonFrom($payload));

            $this->finish($paymentRequest, $eventType, 'recorded', $payment);

            return;
        }

        $transition = self::TRANSITIONS[$operation] ?? null;

        if (self::OUTCOME_SUCCESS !== $outcome || null === $transition) {
            // An `unknown` outcome, or an operation with nothing to reflect. Recorded and
            // acknowledged; the gateway is not asked to explain itself and nothing is guessed.
            $this->finish($paymentRequest, $eventType, 'no_action', $payment);

            return;
        }

        // **The state machine's own answer, not a caught exception.** Asking whether the payment
        // can make the move covers the reordered delivery, the replayed one and the operator who
        // did the same thing in both places — all of which are the *same* situation from here: the
        // payment already holds the state the event describes. Catching the exception instead
        // would treat a normal event as an error and would also swallow a genuine misconfiguration.
        if (!$this->stateMachine->can($payment, PaymentTransitions::GRAPH, $transition)) {
            $this->finish($paymentRequest, $eventType, 'already_applied', $payment);

            return;
        }

        $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);

        $this->finish($paymentRequest, $eventType, 'applied', $payment);
    }

    /**
     * Splits `transaction.refund.success` into the operation and the outcome.
     *
     * @return array{string, string}
     */
    private static function readEventType(string $eventType): array
    {
        $parts = explode('.', $eventType);

        return [$parts[1] ?? '', $parts[2] ?? ''];
    }

    /**
     * The gateway's own sentence about what went wrong, which is the only description some
     * failures have.
     *
     * @param array<string, mixed> $payload
     */
    private static function reasonFrom(array $payload): ?string
    {
        $body = $payload['event_body'] ?? [];
        $action = is_array($body) ? ($body['action'] ?? []) : [];
        $text = is_array($action) ? ($action['response_text'] ?? null) : null;

        return is_string($text) && '' !== trim($text) ? trim($text) : null;
    }

    /**
     * Every event that reaches a payment completes its request, including the ones that changed
     * nothing. A failed request would mean the store could not handle the delivery, and that is
     * not what "the payment was already refunded" means.
     */
    private function finish(PaymentRequestInterface $paymentRequest, string $eventType, string $result, PaymentInterface $payment): void
    {
        $paymentRequest->setResponseData([
            'event_type' => $eventType,
            'result' => $result,
            'payment_state' => $payment->getState(),
        ]);

        $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);
    }
}
