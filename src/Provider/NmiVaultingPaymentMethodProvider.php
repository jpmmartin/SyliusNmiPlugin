<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Provider;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;

/**
 * Which NMI account a card added from the account area belongs to.
 *
 * A stored card lives inside one gateway account and cannot be charged against another, so this
 * has to be decided before the browser tokenises anything — the token is minted with that
 * account's tokenisation key.
 *
 * **It answers only when the channel has exactly one NMI method that accepts saved cards.** With
 * two, picking one silently would file the card against an account the shopper never chose, and
 * choosing properly means asking them before the card form is mounted rather than after. That is a
 * page this change never specified, so the honest behaviour is to offer nothing rather than guess.
 */
final readonly class NmiVaultingPaymentMethodProvider
{
    /** @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethodRepository */
    public function __construct(
        private PaymentMethodRepositoryInterface $paymentMethodRepository,
    ) {
    }

    public function forChannel(ChannelInterface $channel): ?PaymentMethodInterface
    {
        $candidates = $this->savingMethodsIn($channel);

        return 1 === count($candidates) ? $candidates[0] : null;
    }

    /**
     * Whether this channel offers saved cards at all.
     *
     * A weaker question than the one above and asked for a different reason: *which* account a new
     * card belongs to has no answer when there are two, but *whether the store does this* has one —
     * and that is what decides whether a shopper is shown a saved-cards page in their account.
     */
    public function savesCardsIn(ChannelInterface $channel): bool
    {
        return [] !== $this->savingMethodsIn($channel);
    }

    /** @return list<PaymentMethodInterface> */
    private function savingMethodsIn(ChannelInterface $channel): array
    {
        $candidates = [];

        foreach ($this->paymentMethodRepository->findEnabledForChannel($channel) as $paymentMethod) {
            $gatewayConfig = $paymentMethod->getGatewayConfig();

            if (NmiGatewayFactory::NAME !== $gatewayConfig?->getFactoryName()) {
                continue;
            }

            // A store that never turned card saving on has not opted into any of this.
            if (true !== ($gatewayConfig->getConfig()[NmiGatewayFactory::CONFIG_STORE_CARDS] ?? false)) {
                continue;
            }

            $candidates[] = $paymentMethod;
        }

        return $candidates;
    }
}
