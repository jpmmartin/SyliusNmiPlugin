<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Behat\Page\Admin\PaymentMethod;

use Sylius\Behat\Page\Admin\PaymentMethod\UpdatePage as BaseUpdatePage;

final class UpdatePage extends BaseUpdatePage implements NmiGatewayConfigurationPageInterface
{
    use NmiGatewayConfigurationElements;
}
