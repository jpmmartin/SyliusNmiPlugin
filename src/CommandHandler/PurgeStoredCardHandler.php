<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandHandler;

use JpmMartin\SyliusNmiPlugin\Command\PurgeStoredCard;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiClientInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfigurationProviderInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;

/**
 * Removes a vault record the store has already forgotten.
 *
 * **Failing here is the correct behaviour**, not something to be caught and hidden: the transport
 * retries a message whose handler threw, and a purge that gave up quietly would leave a card at the
 * gateway that nothing in the store can ever point at again. So the only refusal treated as success
 * is the gateway saying it has no such record, which is the state being asked for.
 *
 * @see PurgeStoredCard for why the identifiers travel rather than the row
 */
final class PurgeStoredCardHandler
{
    /** @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethodRepository */
    public function __construct(
        private readonly PaymentMethodRepositoryInterface $paymentMethodRepository,
        private readonly NmiGatewayConfigurationProviderInterface $configurationProvider,
        private readonly NmiClientInterface $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PurgeStoredCard $command): void
    {
        $paymentMethod = $this->paymentMethodRepository->findOneBy(['code' => $command->paymentMethodCode]);

        if (!$paymentMethod instanceof PaymentMethodInterface) {
            // The credentials this record could be removed with no longer exist, so no number of
            // retries will ever succeed. Recorded rather than retried, because a message that
            // cannot be delivered is noise on every queue it sits in — and an operator who removed
            // the payment method is the only person who can now clear the record by hand.
            $this->logger->warning('Cannot purge an NMI vault record: no payment method with that code remains.', [
                'payment_method_code' => $command->paymentMethodCode,
            ]);

            return;
        }

        try {
            $configuration = $this->configurationProvider->fromPaymentMethod($paymentMethod);
        } catch (NmiGatewayException $exception) {
            // Same shape as above and for the same reason: an incomplete configuration is not a
            // condition that improves by being asked again in thirty seconds.
            $this->logger->warning('Cannot purge an NMI vault record: the payment method is not configured.', [
                'payment_method_code' => $command->paymentMethodCode,
                'reason' => $exception->getMessage(),
            ]);

            return;
        }

        try {
            $this->client->deleteVaultRecord($configuration, $command->vaultId);
        } catch (NmiGatewayException $exception) {
            // A record the gateway no longer has is a purge that already happened. Anything else it
            // refuses is rethrown, so the transport tries again.
            if (!$exception->isAlreadyGone()) {
                throw $exception;
            }
        }
    }
}
