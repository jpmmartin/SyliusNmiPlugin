<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Behat\Page\Admin\PaymentMethod;

use Behat\Mink\Element\NodeElement;
use Sylius\Behat\Page\Admin\Crud\IndexPage as BaseIndexPage;

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
