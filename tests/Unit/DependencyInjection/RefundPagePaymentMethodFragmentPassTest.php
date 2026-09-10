<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Unit\DependencyInjection;

use JpmMartin\SyliusNmiPlugin\DependencyInjection\Compiler\RefundPagePaymentMethodFragmentPass;
use PHPUnit\Framework\TestCase;
use Sylius\TwigHooks\Hookable\HookableTemplate;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * The one hookable of the refund plugin's refund page this plugin repoints, and the two ways a
 * store keeps it from doing so.
 *
 * The hookable is built the way Twig Hooks builds it — hook name, hookable name, template, then
 * the rest — so the argument the pass replaces is the one the real registration puts there.
 */
final class RefundPagePaymentMethodFragmentPassTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/nmi-refund-page-' . bin2hex(random_bytes(4));
        mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        $this->remove($this->projectDir);
    }

    public function testItPointsTheRefundPluginsFragmentAtThePluginsCopy(): void
    {
        $container = $this->aContainerWith(RefundPagePaymentMethodFragmentPass::REFUND_PLUGIN_TEMPLATE);

        (new RefundPagePaymentMethodFragmentPass())->process($container);

        self::assertSame(RefundPagePaymentMethodFragmentPass::TEMPLATE, $this->templateOf($container));
    }

    public function testThePluginsCopyIsWhereItSaysItIs(): void
    {
        $relative = substr(RefundPagePaymentMethodFragmentPass::TEMPLATE, \strlen('@JpmMartinSyliusNmiPlugin/'));

        self::assertFileExists(__DIR__ . '/../../../templates/' . $relative);
    }

    public function testATemplateTheStoreConfiguredIsLeftAlone(): void
    {
        $container = $this->aContainerWith('@App/refund/payment_method.html.twig');

        (new RefundPagePaymentMethodFragmentPass())->process($container);

        self::assertSame('@App/refund/payment_method.html.twig', $this->templateOf($container));
    }

    public function testAFileTheStoreOverridesIsLeftAlone(): void
    {
        $override = $this->projectDir . '/templates/bundles/SyliusRefundPlugin/admin/order/refund/content/sections/form/fields';
        mkdir($override, 0777, true);
        touch($override . '/payment_method.html.twig');
        $container = $this->aContainerWith(RefundPagePaymentMethodFragmentPass::REFUND_PLUGIN_TEMPLATE);

        (new RefundPagePaymentMethodFragmentPass())->process($container);

        self::assertSame(RefundPagePaymentMethodFragmentPass::REFUND_PLUGIN_TEMPLATE, $this->templateOf($container), 'The store owns this fragment; Twig would serve its file, and the pass leaves it to.');
    }

    public function testWithoutTheRefundPluginThereIsNothingToDo(): void
    {
        $container = new ContainerBuilder();

        (new RefundPagePaymentMethodFragmentPass())->process($container);

        self::assertFalse($container->hasDefinition(RefundPagePaymentMethodFragmentPass::HOOKABLE_SERVICE));
    }

    private function aContainerWith(string $template): ContainerBuilder
    {
        $container = new ContainerBuilder();
        // As a store has them: the templates directory named through the project directory.
        $container->setParameter('kernel.project_dir', $this->projectDir);
        $container->setParameter('twig.default_path', '%kernel.project_dir%/templates');
        $container->setDefinition(RefundPagePaymentMethodFragmentPass::HOOKABLE_SERVICE, new Definition(HookableTemplate::class, [
            'sylius_refund.admin.order.refund.content.sections.form.fields',
            'payment_method',
            $template,
            [],
            [],
            0,
            true,
        ]));

        return $container;
    }

    private function templateOf(ContainerBuilder $container): string
    {
        $template = $container->getDefinition(RefundPagePaymentMethodFragmentPass::HOOKABLE_SERVICE)->getArgument(2);
        self::assertIsString($template);

        return $template;
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
                $this->remove($path . '/' . $entry);
            }
            rmdir($path);

            return;
        }

        unlink($path);
    }
}
