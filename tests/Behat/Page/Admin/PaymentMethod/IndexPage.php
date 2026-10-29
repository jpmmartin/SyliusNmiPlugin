<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Behat\Page\Admin\PaymentMethod;

use Behat\Mink\Element\NodeElement;
use Sylius\Behat\Page\Admin\Crud\IndexPage as CrudIndexPage;
use Sylius\Behat\Page\Admin\PaymentMethod\IndexPage as PaymentMethodIndexPage;

// Sylius 2.3 gave the payment method index a page of its own, which its own context now requires;
// Sylius 2.2 has only the generic one. Whichever this install ships is the one extended.
if (!class_exists(BaseIndexPage::class, false)) {
    class_alias(class_exists(PaymentMethodIndexPage::class) ? PaymentMethodIndexPage::class : CrudIndexPage::class, BaseIndexPage::class);
}

final class IndexPage extends BaseIndexPage
{
    /**
     * The labels in the "Create" dropdown, one link per registered gateway factory.
     *
     * @return list<string>
     */
    public function getAvailableGatewayFactories(): array
    {
        $links = $this->getDocument()->findAll('css', 'a[href*="/payment-methods/new/"]');

        return array_values(array_map(static fn (NodeElement $link): string => trim($link->getText()), $links));
    }
}
