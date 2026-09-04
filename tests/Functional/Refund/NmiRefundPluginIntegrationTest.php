<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Refund;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\RefundPlugin\Provider\SupportedRefundPaymentMethodsProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;
use Twig\Environment;

/**
 * `sylius/refund-plugin` is optional and stays optional.
 *
 * This plugin refunds from the order screen on its own and needs nothing from that package. What
 * the package adds is its own refund screens, and those only offer gateways named in a list it
 * keeps — a gateway missing from that list is not refused, it simply never appears, which is the
 * kind of silence an operator reads as a broken plugin.
 *
 * Every test here is skipped in the configuration that does not have the package, which is the
 * point: the suite must pass in both.
 */
final class NmiRefundPluginIntegrationTest extends KernelTestCase
{
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 1299;

    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->manager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->manager->rollback();

        parent::tearDown();
    }

    /**
     * The scenario the specification words as *the store has the optional refund plugin installed
     * and its supported-gateways list names this gateway*. Asked of the refund plugin's own
     * provider, because it is the thing that decides.
     */
    public function testTheGatewayIsOfferedOnceTheListNamesIt(): void
    {
        $this->onlyWithTheRefundPlugin();

        $order = $this->anOrderPaidByCard();

        $offered = new SupportedRefundPaymentMethodsProvider(
            self::getContainer()->get('sylius.repository.payment_method'),
            ['offline', 'nmi'],
        );

        $codes = array_map(
            static fn (PaymentMethodInterface $method): string => (string) $method->getCode(),
            $offered->findForOrder($order),
        );

        self::assertContains((string) $order->getPayments()->first()->getMethod()?->getCode(), $codes);
    }

    /** And the other half of it: without the entry the gateway is not offered, and says nothing. */
    public function testTheGatewayIsNotOfferedWhileTheListOmitsIt(): void
    {
        $this->onlyWithTheRefundPlugin();

        $order = $this->anOrderPaidByCard();

        $offered = new SupportedRefundPaymentMethodsProvider(
            self::getContainer()->get('sylius.repository.payment_method'),
            ['offline'],
        );

        self::assertSame([], $offered->findForOrder($order));
    }

    /**
     * Which is why the notice exists. Nothing else in the store would tell the operator that the
     * two packages are installed and not talking to each other.
     */
    public function testTheOperatorIsToldWhatToAddAndWhereItMatters(): void
    {
        $this->onlyWithTheRefundPlugin();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $notice = $twig->render('@JpmMartinSyliusNmiPlugin/admin/payment_method/form/gateway_configuration/nmi_refund_notice.html.twig');

        // The test application does not name this gateway, so the notice is the one that appears.
        self::assertStringContainsString('sylius_refund.supported_gateways', $notice);
        self::assertStringContainsString('- nmi', $notice);
        self::assertStringNotContainsString('jpm_martin_sylius_nmi.admin.', $notice, 'The notice goes through the catalogue.');
    }

    /** The conditional service is only there when the bundle that gives it its parameter is. */
    public function testTheIntegrationIsAbsentWhenThePackageIs(): void
    {
        if (self::hasTheRefundPlugin()) {
            self::markTestSkipped('This is the assertion for the configuration without the refund plugin.');
        }

        self::assertFalse(
            self::getContainer()->has('jpm_martin_sylius_nmi.twig.refund_support'),
            'A service reading a parameter that does not exist would break the container for every store without the package.',
        );
    }

    private function anOrderPaidByCard(): OrderInterface
    {
        /** @var OrderInterface $order */
        $order = $this->newPaymentRequest()->getPayment()->getOrder();

        return $order;
    }

    private function onlyWithTheRefundPlugin(): void
    {
        if (!self::hasTheRefundPlugin()) {
            self::markTestSkipped('The optional refund plugin is not installed in this configuration.');
        }
    }

    private static function hasTheRefundPlugin(): bool
    {
        return class_exists(SupportedRefundPaymentMethodsProvider::class);
    }

    protected function paymentRequestManager(): EntityManagerInterface
    {
        return $this->manager;
    }
}
