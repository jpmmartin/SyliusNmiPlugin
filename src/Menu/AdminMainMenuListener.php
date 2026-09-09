<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

/**
 * Puts the gateway's own news under Sales, next to orders and payments.
 *
 * **Unconditional, unlike the shopper's saved-cards entry.** That one is hidden where the feature
 * is off, because a store that never turned card saving on is promised no change. This one has no
 * setting to be off: a chargeback is money taken back, and a store that would rather not see it is
 * not a preference worth offering. The page is empty until the gateway reports something, which is
 * the state most stores will always be in.
 *
 * KnpMenu again, and again no per-item priority, so it lands after the platform's own entries in
 * that submenu. The label is a translation key; the menu template runs `|trans` over it.
 */
final class AdminMainMenuListener
{
    public function addGatewayNotices(MenuBuilderEvent $event): void
    {
        $sales = $event->getMenu()->getChild('sales');
        if (null === $sales) {
            return;
        }

        $sales
            ->addChild('jpm_martin_sylius_nmi_gateway_notices', ['route' => 'jpm_martin_sylius_nmi_admin_gateway_notice_index'])
            ->setLabel('jpm_martin_sylius_nmi.admin.gateway_notice.menu')
            ->setLabelAttribute('icon', 'tabler:alert-triangle')
        ;
    }
}
