<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Lets the refund plugin's refund page render an order whose NMI payment is refunded.
 *
 * The refund plugin keeps that page for a fully refunded order, as a history, and sends the
 * operator there after every refund; its `payment_method` fragment reads the method off the
 * order's last *completed* payment without asking whether there is one. An offline refund never
 * moves the payment, so the refund plugin never meets its own null; an NMI refund does, once the
 * money is back, and the fragment fails. Twig Hooks keeps every hookable as a service with the
 * template path as an argument, so this pass points that one hookable at the plugin's own copy of
 * the fragment, which reads the last completed payment when there is one and the last payment
 * otherwise.
 *
 * Only when nobody else has. A store that configured another template for the hookable, or that
 * overrides the file under `templates/bundles/SyliusRefundPlugin/`, keeps its own: the pass runs
 * after every configuration is merged, which is what lets it see that. Without the refund plugin
 * the hookable does not exist and there is nothing to do.
 */
final class RefundPagePaymentMethodFragmentPass implements CompilerPassInterface
{
    public const HOOKABLE_SERVICE = 'sylius_twig_hooks.hook.sylius_refund.admin.order.refund.content.sections.form.fields.hookable.payment_method';

    public const REFUND_PLUGIN_TEMPLATE = '@SyliusRefundPlugin/admin/order/refund/content/sections/form/fields/payment_method.html.twig';

    public const TEMPLATE = '@JpmMartinSyliusNmiPlugin/admin/order/refund/payment_method.html.twig';

    /** Where Symfony looks for a store's own version of a bundle's template, below `twig.default_path`. */
    private const OVERRIDE_FILE = '/bundles/SyliusRefundPlugin/admin/order/refund/content/sections/form/fields/payment_method.html.twig';

    /** The template's position among `HookableTemplate`'s constructor arguments, as Twig Hooks registers them. */
    private const TEMPLATE_ARGUMENT = 2;

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::HOOKABLE_SERVICE)) {
            return;
        }

        $hookable = $container->getDefinition(self::HOOKABLE_SERVICE);
        $arguments = $hookable->getArguments();

        if (self::REFUND_PLUGIN_TEMPLATE !== ($arguments[self::TEMPLATE_ARGUMENT] ?? null)) {
            return;
        }

        if ($this->storeOverridesTheFile($container)) {
            return;
        }

        $hookable->replaceArgument(self::TEMPLATE_ARGUMENT, self::TEMPLATE);
    }

    private function storeOverridesTheFile(ContainerBuilder $container): bool
    {
        if (!$container->hasParameter('twig.default_path')) {
            return false;
        }

        $templates = $container->getParameterBag()->resolveValue($container->getParameter('twig.default_path'));

        return \is_string($templates) && is_file($templates . self::OVERRIDE_FILE);
    }
}
