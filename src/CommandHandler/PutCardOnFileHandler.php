<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandHandler;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusNmiPlugin\Command\PutCardOnFile;
use JpmMartin\SyliusNmiPlugin\Entity\NmiCardOnFileInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiDeclinedException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiCardVerifierInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfigurationProviderInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\CardVerification;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\OrderReference;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\ThreeDSecureResult;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiCardOnFileRepositoryInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Resource\Factory\FactoryInterface;

/**
 * Second phase on a method that takes payment later: verify the card the browser tokenised and put
 * it on file, with nothing charged.
 *
 * The outcomes follow the charge's, and for the same reasons:
 *
 * - accepted: the verification is recorded, the card is on file for this payment, and the payment
 *   moves to processing — waiting, with the order awaiting payment
 * - declined or refused: the request fails with the reason and **the payment is left alone**, so
 *   the order stays payable with another card
 * - no answer: the request fails and nothing is recorded as on file. The gateway may have kept a
 *   vault record nobody here points at; that is the price of a question that was never answered,
 *   and a card recorded on a guess would be worse
 *
 * @internal
 */
final class PutCardOnFileHandler
{
    /** A second card for a payment that already holds one — through the shop API, say. */
    public const ALREADY_ON_FILE_MESSAGE_KEY = 'jpm_martin_sylius_nmi.payment.card_already_on_file';

    /**
     * @param FactoryInterface<NmiCardOnFileInterface> $cardOnFileFactory
     */
    public function __construct(
        private readonly PaymentRequestProviderInterface $paymentRequestProvider,
        private readonly NmiGatewayConfigurationProviderInterface $configurationProvider,
        private readonly NmiCardVerifierInterface $verifier,
        private readonly NmiTransactionRecorderInterface $recorder,
        private readonly NmiCardOnFileRepositoryInterface $cardsOnFile,
        private readonly FactoryInterface $cardOnFileFactory,
        private readonly ObjectManager $manager,
        private readonly StateMachineInterface $stateMachine,
    ) {
    }

    public function __invoke(PutCardOnFile $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);

        $payment = $paymentRequest->getPayment();
        if (!$payment instanceof PaymentInterface) {
            throw new \LogicException(sprintf('Expected a core payment, got "%s".', $payment::class));
        }

        /** @var array<string, mixed> $payload */
        $payload = is_array($paymentRequest->getPayload()) ? $paymentRequest->getPayload() : [];

        $token = is_string($payload['payment_token'] ?? null) && '' !== trim($payload['payment_token']) ? trim($payload['payment_token']) : null;
        if (null === $token) {
            // Not a submission: the pay page announces this command on every view once the request
            // is in progress. Left untouched so the page can render the form again.
            return;
        }

        // Asked before the gateway, because the gateway would happily verify and keep a second
        // card: one payment, one card, and the later charge must never have to choose.
        if (null !== $this->cardsOnFile->findHeldBy($payment)) {
            $this->fail($paymentRequest, self::ALREADY_ON_FILE_MESSAGE_KEY, 'This payment already holds a card on file.');

            return;
        }

        $method = $paymentRequest->getMethod();

        try {
            $response = $this->verifier->verifyAndStore(
                $this->configurationProvider->fromPaymentMethod($method),
                new CardVerification(
                    paymentToken: $token,
                    currencyCode: (string) $payment->getCurrencyCode(),
                    orderId: OrderReference::of($payment->getOrder()),
                    threeDSecure: ThreeDSecureResult::fromPayload($payload),
                ),
            );
        } catch (NmiDeclinedException $exception) {
            // The gateway gave the attempt an identifier, so it is recorded: a notification about
            // it has to resolve to this payment.
            $this->recorder->record($payment, $exception->getResponse(), NmiTransactionInterface::TYPE_VALIDATE);
            $this->fail($paymentRequest, 'jpm_martin_sylius_nmi.payment.declined', $exception->getDeclineReason());

            return;
        } catch (NmiGatewayException $exception) {
            $this->fail($paymentRequest, 'jpm_martin_sylius_nmi.payment.failed', $exception->getGatewayMessage() ?? $exception->getMessage());

            return;
        } catch (NmiTransportException $exception) {
            $this->fail($paymentRequest, 'jpm_martin_sylius_nmi.payment.unreachable', $exception->getMessage());

            return;
        }

        $this->recorder->record($payment, $response, NmiTransactionInterface::TYPE_VALIDATE);

        $vaultId = $response->customerVaultId;
        if (null === $vaultId || '' === $vaultId || !$method instanceof PaymentMethodInterface) {
            // Verified but not kept: there is nothing to charge later, so nothing is on file. Seen
            // as a failure the shopper can retry rather than as a card the store only thinks it has.
            $this->fail($paymentRequest, 'jpm_martin_sylius_nmi.payment.failed', 'The gateway verified the card but did not keep it.');

            return;
        }

        $this->manager->persist($this->cardOnFile($payment, $method, $vaultId, $response));

        if ($this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_PROCESS)) {
            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_PROCESS);
        }

        $paymentRequest->setResponseData([
            'transaction_id' => $response->transactionId,
            'status' => $response->status,
            'response_code' => $response->responseCode,
            'response_text' => $response->responseText,
            'card_on_file' => true,
        ]);

        $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);
    }

    private function cardOnFile(PaymentInterface $payment, PaymentMethodInterface $method, string $vaultId, NmiResponse $response): NmiCardOnFileInterface
    {
        $card = $this->cardOnFileFactory->createNew();
        $card->setPayment($payment);
        $card->setPaymentMethod($method);
        $card->setVaultId($vaultId);
        // The transaction this verification was: every later charge of the card cites it.
        $card->setInitialTransactionId($response->transactionId);
        $card->setBrand($response->card?->brand);
        $card->setLastFour($response->card?->lastFour);
        $card->setExpiryMonth($response->card?->expiryMonth);
        $card->setExpiryYear($response->card?->expiryYear);

        return $card;
    }

    /** Fails the request and records why. The payment is untouched, which keeps the order payable. */
    private function fail(PaymentRequestInterface $paymentRequest, string $messageKey, string $detail): void
    {
        $paymentRequest->setResponseData([
            'message_key' => $messageKey,
            'detail' => $detail,
        ]);

        $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);
    }
}
