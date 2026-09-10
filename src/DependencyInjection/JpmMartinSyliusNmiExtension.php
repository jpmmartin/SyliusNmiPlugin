<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\DependencyInjection;

use JpmMartin\SyliusNmiPlugin\Command\NotifyCardholder;
use JpmMartin\SyliusNmiPlugin\Command\PurgeStoredCard;
use JpmMartin\SyliusNmiPlugin\Entity\NmiGatewayNotice;
use JpmMartin\SyliusNmiPlugin\Mailer\NmiEmails;
use JpmMartin\SyliusNmiPlugin\Refund\RefundPaymentTransitions;
use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Sylius\Bundle\ResourceBundle\DependencyInjection\Extension\AbstractResourceExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\Yaml\Yaml;

/** @internal */
final class JpmMartinSyliusNmiExtension extends AbstractResourceExtension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $this->registerResources('jpm_martin_sylius_nmi', $config['driver'], $config['resources'], $container);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));

        $loader->load('services.xml');

        // Refunding does not depend on the refund plugin — this plugin refunds from the order
        // screen on its own. What depends on it is offering NMI on that plugin's screens and
        // giving the money back when they ask, whose services name ids that only exist once that
        // bundle has loaded its own configuration.
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
                    // A mail server that is slow or down must not turn a webhook delivery into a
                    // failure the gateway then retries twenty times over three days.
                    NotifyCardholder::class => 'main',
                ],
            ],
        ]);

        // The one email this plugin sends. Prepended rather than left to the store, because an
        // email the store has to declare by hand is an email that silently does not exist.
        $container->prependExtensionConfig('sylius_mailer', [
            'emails' => [
                NmiEmails::STORED_CARD_ATTENTION => [
                    // The subject is chosen by the template, which knows whether the card was
                    // closed or merely flagged; this key is the fallback the mailer needs.
                    'subject' => 'jpm_martin_sylius_nmi.email.stored_card_attention.subject.closed',
                    'template' => '@JpmMartinSyliusNmiPlugin/email/storedCardAttention.html.twig',
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

        // The plugin's own transition on the refund plugin's refund-payment workflow: the one a
        // gateway approval takes, while the plugin's manual `complete` stays guarded shut for NMI
        // methods. Prepended from here rather than shipped as a workflow file, because a file
        // would have to be imported by the store and could not be conditional — and declaring a
        // transition on a workflow that does not exist breaks the container of every store
        // without the refund plugin.
        $container->prependExtensionConfig('framework', [
            'workflows' => [
                'sylius_refund_refund_payment' => [
                    'transitions' => [
                        // The refund plugin's own state names, written out rather than read off
                        // its interface so that this file stays analysable in a store without it.
                        RefundPaymentTransitions::TRANSITION_CONFIRM_GATEWAY_REFUND => [
                            'from' => 'new',
                            'to' => 'completed',
                        ],
                    ],
                ],
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
