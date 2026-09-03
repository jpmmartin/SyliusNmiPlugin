<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Behat\Page\Admin\PaymentMethod;

use Sylius\Behat\Page\Admin\PaymentMethod\CreatePage as BaseCreatePage;

/**
 * Sylius's own create page, taught where this plugin's gateway fields are. The selectors are the
 * data-test attributes the hook template emits in the test environment.
 */
final class CreatePage extends BaseCreatePage implements NmiGatewayConfigurationPageInterface
{
    use NmiGatewayConfigurationElements;
}
