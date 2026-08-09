<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    /**
     * @psalm-suppress UnusedVariable
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('jpm_martin_sylius_nmi');
        $rootNode = $treeBuilder->getRootNode();

        return $treeBuilder;
    }
}
