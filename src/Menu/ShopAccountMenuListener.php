<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Menu;

use JpmMartin\SyliusNmiPlugin\Provider\NmiVaultingPaymentMethodProvider;
use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Context\ChannelNotFoundException;
use Sylius\Component\Core\Model\ChannelInterface;

/**
 * Puts the saved cards in the shopper's account menu, **but only where the store saves cards**.
 *
 * That condition is the requirement rather than a nicety: a store that never turns card saving on
 * is promised no observable change anywhere in the storefront, and an extra entry in every
 * shopper's account menu is about as observable as it gets. It also spares a store that skipped
 * the account routes — which the README calls optional — a menu that cannot render, because
 * KnpMenu resolves the route when the page is drawn and a missing one takes the account area with
 * it.
 *
 * That menu is KnpMenu and has no Twig hook, which is why this is an event listener and not a
 * hookable — getting those two the wrong way round is the easiest afternoon to lose here. It also
 * has no per-item priority, so the entry lands after the platform's own five. The label is a
 * translation key: the menu template runs `|trans` over it.
 *
 * @internal
 */
final class ShopAccountMenuListener
{
    public function __construct(
        private readonly ChannelContextInterface $channelContext,
        private readonly NmiVaultingPaymentMethodProvider $vaultingPaymentMethodProvider,
    ) {
    }

    public function addStoredCards(MenuBuilderEvent $event): void
    {
        if (!$this->storeSavesCards()) {
            return;
        }

        $event->getMenu()
            ->addChild('nmi_stored_cards', ['route' => 'jpm_martin_sylius_nmi_shop_account_stored_card_index'])
            ->setLabel('jpm_martin_sylius_nmi.ui.saved_cards')
            ->setLabelAttribute('icon', 'tabler:credit-card')
        ;
    }

    private function storeSavesCards(): bool
    {
        try {
            $channel = $this->channelContext->getChannel();
        } catch (ChannelNotFoundException) {
            // No channel is no storefront, and a menu with no store behind it has nothing to say.
            return false;
        }

        return $channel instanceof ChannelInterface && $this->vaultingPaymentMethodProvider->savesCardsIn($channel);
    }
}
