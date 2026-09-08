<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

/**
 * Puts the saved cards in the shopper's account menu.
 *
 * That menu is KnpMenu and has no Twig hook, which is why this is an event listener and not a
 * hookable — getting those two the wrong way round is the easiest afternoon to lose here. It also
 * has no per-item priority, so the entry lands after the platform's own five. The label is a
 * translation key: the menu template runs `|trans` over it.
 */
final class ShopAccountMenuListener
{
    public function addStoredCards(MenuBuilderEvent $event): void
    {
        $event->getMenu()
            ->addChild('nmi_stored_cards', ['route' => 'jpm_martin_sylius_nmi_shop_account_stored_card_index'])
            ->setLabel('jpm_martin_sylius_nmi.ui.saved_cards')
            ->setLabelAttribute('icon', 'tabler:credit-card')
        ;
    }
}
