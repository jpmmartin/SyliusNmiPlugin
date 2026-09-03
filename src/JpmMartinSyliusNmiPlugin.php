<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin;

use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Sylius\Bundle\ResourceBundle\AbstractResourceBundle;
use Sylius\Bundle\ResourceBundle\SyliusResourceBundle;

final class JpmMartinSyliusNmiPlugin extends AbstractResourceBundle
{
    use SyliusPluginTrait;

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    /** @return list<string> */
    public function getSupportedDrivers(): array
    {
        return [SyliusResourceBundle::DRIVER_DOCTRINE_ORM];
    }

    protected function getModelNamespace(): string
    {
        return 'JpmMartin\SyliusNmiPlugin\Entity';
    }

    /**
     * The bundle path is the repository root, and this plugin keeps its configuration under
     * config/ rather than Resources/config/, so the default location would be a stray top-level
     * directory. The mapping files sit next to the rest of the configuration instead.
     */
    protected function getConfigFilesPath(): string
    {
        return $this->getPath() . '/config/doctrine/model';
    }
}
