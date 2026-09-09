<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\DependencyInjection;

use JpmMartin\SyliusNmiPlugin\Command\PurgeStoredCard;
use JpmMartin\SyliusNmiPlugin\Entity\NmiGatewayNotice;
use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Sylius\Bundle\ResourceBundle\DependencyInjection\Extension\AbstractResourceExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\Yaml\Yaml;

final class JpmMartinSyliusNmiExtension extends AbstractResourceExtension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $apiBaseUrl = $config['api_base_url'];
        $container->setParameter('jpm_martin_sylius_nmi.api_base_url', is_string($apiBaseUrl) && '' !== trim($apiBaseUrl) ? $apiBaseUrl : null);

        $this->registerResources('jpm_martin_sylius_nmi', $config['driver'], $config['resources'], $container);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));

        $loader->load('services.xml');

        // Refunding does not depend on the refund plugin — this plugin refunds from the order
        // screen on its own. What depends on it is the notice below, and the parameter it reads,
        // which only exists once that bundle has loaded its own configuration.
        if (self::hasRefundPlugin($container)) {
            $loader->load('refund_plugin.xml');
        }
    }

    public function prepend(ContainerBuilder $container): void
    {
        $this->prependDoctrineMigrations($container);

        // Forgetting a card at the gateway must not be able to make deleting a customer fail, and
        // must not be lost when the gateway is down — so it is queued rather than performed in the
        // request. `main` is the platform's own asynchronous transport, the one Sylius routes its
        // own deferred work to, with a failure transport already attached: a purge is retried and,
        // if the retries run out, parked there rather than dropped.
        //
        // Prepended rather than left in `config/config.yaml`, which a store imports by hand. A
        // store that forgot the import would otherwise get this message handled synchronously and
        // silently lose the retries the requirement is about.
        $container->prependExtensionConfig('framework', [
            'messenger' => [
                'routing' => [
                    PurgeStoredCard::class => 'main',
                ],
            ],
        ]);

        // The admin list of what the gateway reported. Prepended for the same reason the messenger
        // routing above is: a grid a store has to import by hand is a grid most stores will not
        // have, and the requirement is that a chargeback is *always* visible.
        //
        // Read from YAML rather than written as an array here because it is a page's worth of
        // configuration and it reads far better as one. `PARSE_CONSTANT` is what lets the filter
        // name the entity's own constants instead of repeating their values.
        // The parameter the grid names does not exist yet: resources are registered in `load()`,
        // which runs after every `prepend()`. Declaring the default here is enough for the grid to
        // validate, and `registerResources` overwrites it a moment later with whatever class the
        // store actually configured — so a replaced model still reaches the grid.
        $container->setParameter('jpm_martin_sylius_nmi.model.nmi_gateway_notice.class', NmiGatewayNotice::class);

        $container->prependExtensionConfig(
            'sylius_grid',
            (array) (Yaml::parseFile(__DIR__ . '/../../config/grids/gateway_notice.yaml', Yaml::PARSE_CONSTANT)['sylius_grid'] ?? []),
        );

        if (!self::hasRefundPlugin($container)) {
            return;
        }

        // Registered from here rather than from `config/twig_hooks/`, because that file is a YAML
        // import in the consuming application and a YAML import cannot ask which bundles exist.
        $notice = [
            'template' => '@JpmMartinSyliusNmiPlugin/admin/payment_method/form/gateway_configuration/nmi_refund_notice.html.twig',
            'priority' => 10,
        ];

        $container->prependExtensionConfig('sylius_twig_hooks', [
            'hooks' => [
                'sylius_admin.payment_method.create.content.form.sections.gateway_configuration.nmi' => ['nmi_refund_notice' => $notice],
                'sylius_admin.payment_method.update.content.form.sections.gateway_configuration.nmi' => ['nmi_refund_notice' => $notice],
            ],
        ]);
    }

    private static function hasRefundPlugin(ContainerBuilder $container): bool
    {
        /** @var array<string, class-string> $bundles */
        $bundles = $container->getParameter('kernel.bundles');

        return isset($bundles['SyliusRefundPlugin']);
    }

    protected function getMigrationsNamespace(): string
    {
        // Owned by this plugin, never the skeleton's generic "DoctrineMigrations": that namespace is
        // the one a consuming application uses for its own migrations, and the migrations table
        // records the fully qualified class name, so the namespace is frozen once a migration ships.
        return 'JpmMartin\\SyliusNmiPlugin\\Migrations';
    }

    protected function getMigrationsDirectory(): string
    {
        return '@JpmMartinSyliusNmiPlugin/src/Migrations';
    }

    /** @return array<string> */
    protected function getNamespacesOfMigrationsExecutedBefore(): array
    {
        return [
            'Sylius\Bundle\CoreBundle\Migrations',
        ];
    }
}
