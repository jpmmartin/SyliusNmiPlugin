<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\EventListener;

use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiExceptionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiClientInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfigurationProviderInterface;
use JpmMartin\SyliusNmiPlugin\StoredCard\NmiDefaultCard;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;

/**
 * Deleting a stored card has to reach the gateway too, or the store forgets a card the gateway
 * keeps — and after that nothing points at the vault record, so nobody can ever remove it.
 *
 * It runs *before* the row is removed, so the gateway is asked first and a refusal leaves both
 * halves intact. The alternative — delete locally, hope the gateway agrees — trades a visible
 * failure for a silent orphan.
 *
 * A card the gateway no longer has is a deletion that already happened, so that refusal alone is
 * treated as success. Everything else stops the deletion and says why.
 */
final readonly class NmiStoredCardDeletionListener
{
    public function __construct(
        private NmiGatewayConfigurationProviderInterface $configurationProvider,
        private NmiClientInterface $client,
        private NmiDefaultCard $defaultCard,
    ) {
    }

    public function __invoke(ResourceControllerEvent $event): void
    {
        $card = $event->getSubject();
        if (!$card instanceof NmiStoredCardInterface) {
            return;
        }

        $paymentMethod = $card->getPaymentMethod();
        $customer = $card->getCustomer();
        $vaultId = $card->getVaultId();

        if (null === $paymentMethod || null === $customer || null === $vaultId) {
            return;
        }

        // Outside the try on purpose. Reading the store's own configuration is not talking to the
        // gateway, and a broken configuration reported as "the gateway refused" sends an operator
        // looking in the wrong place entirely.
        try {
            $configuration = $this->configurationProvider->fromPaymentMethod($paymentMethod);
        } catch (NmiExceptionInterface) {
            $event->stop('jpm_martin_sylius_nmi.stored_card.not_configured');

            return;
        }

        try {
            $this->client->deleteVaultRecord($configuration, $vaultId);
        } catch (NmiGatewayException $exception) {
            // 404 is the gateway saying it has no such record, which is the state being asked for.
            if (!$this->alreadyGone($exception)) {
                $event->stop('jpm_martin_sylius_nmi.stored_card.not_forgotten');

                return;
            }
        } catch (NmiExceptionInterface) {
            // Unreachable is not refused: whether the record is gone is unknown, and removing the
            // row now would strand it with nothing left pointing at it.
            $event->stop('jpm_martin_sylius_nmi.stored_card.gateway_unreachable');

            return;
        }

        // Asked before the row goes, and told which one is leaving, so the survivors elect a new
        // default in the same transaction rather than a moment later.
        $this->defaultCard->electIfNoneRemains($customer, $paymentMethod, $card);
    }

    private function alreadyGone(NmiGatewayException $exception): bool
    {
        return 404 === $exception->getHttpStatus();
    }
}
