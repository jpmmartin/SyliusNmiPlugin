<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\DependencyInjection;

use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCard;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransaction;
use JpmMartin\SyliusNmiPlugin\Repository\NmiStoredCardRepository;
use JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepository;
use Sylius\Bundle\ResourceBundle\Controller\ResourceController;
use Sylius\Bundle\ResourceBundle\SyliusResourceBundle;
use Sylius\Resource\Factory\Factory;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('jpm_martin_sylius_nmi');
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('driver')->defaultValue(SyliusResourceBundle::DRIVER_DOCTRINE_ORM)->end()
                ->scalarNode('api_base_url')
                    ->defaultNull()
                    ->info('Gateway host used for every NMI payment method, e.g. a reseller (white-label) host such as "https://example.transactiongateway.com". Leave unset to derive it from each payment method\'s environment.')
                ->end()
            ->end()
        ;

        $this->addResourcesSection($rootNode);

        return $treeBuilder;
    }

    private function addResourcesSection(ArrayNodeDefinition $node): void
    {
        $node
            ->children()
                ->arrayNode('resources')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('nmi_transaction')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->arrayNode('classes')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('model')->defaultValue(NmiTransaction::class)->cannotBeEmpty()->end()
                                        ->scalarNode('repository')->defaultValue(NmiTransactionRepository::class)->cannotBeEmpty()->end()
                                        ->scalarNode('factory')->defaultValue(Factory::class)->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('nmi_stored_card')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->arrayNode('classes')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('model')->defaultValue(NmiStoredCard::class)->cannotBeEmpty()->end()
                                        ->scalarNode('repository')->defaultValue(NmiStoredCardRepository::class)->cannotBeEmpty()->end()
                                        ->scalarNode('factory')->defaultValue(Factory::class)->end()
                                        // The account pages are the platform's own resource controller, which is
                                        // what lets a route express the ownership query instead of a check.
                                        ->scalarNode('controller')->defaultValue(ResourceController::class)->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }
}
