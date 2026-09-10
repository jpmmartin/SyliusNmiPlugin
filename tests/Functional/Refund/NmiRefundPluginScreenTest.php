<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Refund;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;

/**
 * The refund plugin's own screen, driven over HTTP the way an operator drives it.
 *
 * The other two classes drive the refund plugin's command and this plugin's services. This one
 * drives its controllers, because the fault it guards against lived between them: after every
 * refund the refund plugin redirects to its refund page, and after the last one that page has to
 * render an order whose payment is *refunded* — which the refund plugin's own fragment cannot —
 * and show the operator one message, not a success and a contradiction.
 */
final class NmiRefundPluginScreenTest extends WebTestCase
{
    use BuildsARefundableNmiOrder;

    private KernelBrowser $client;

    private EntityManagerInterface $manager;

    private FakeNmiClient $gateway;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // One kernel for every request, so that the requests run inside this test's transaction
        // and see the order it built.
        $this->client->disableReboot();
        $container = self::getContainer();

        /** @var EntityManagerInterface $manager */
        $manager = $container->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;
        $this->manager->getConnection()->setNestTransactionsWithSavepoints(true);
        $this->manager->beginTransaction();

        $this->gateway = new FakeNmiClient();
        $container->set('jpm_martin_sylius_nmi.gateway.client', $this->gateway);
    }

    protected function tearDown(): void
    {
        $this->manager->rollback();

        parent::tearDown();
    }

    /** The *The refund page still opens after the last refund* scenario, from the screen to the screen. */
    public function testTheWholeAmountRefundedFromTheScreenLandsOnThePageWithOneMessage(): void
    {
        $this->onlyWithTheRefundPlugin();
        [$order, $payment, $adjustmentId] = $this->paidNmiOrder(settled: true);
        $method = $this->methodOf($payment);
        $this->gateway->willApprove('12513502470', '-12.99');
        $this->client->loginUser($this->anAdministrator(), 'admin');

        $page = $this->client->request('GET', $this->refundPageOf($order));
        self::assertResponseIsSuccessful('While the payment stands, the page is the refund plugin\'s as it ships.');
        $token = (string) $page->filter('input[name="_csrf_token"]')->attr('value');

        $this->client->request('POST', sprintf('/admin/orders/%s/refund-units', $order->getNumber()), [
            'sylius_refund_shipments' => [$adjustmentId => ['full' => 'on']],
            'sylius_refund_payment_method' => (string) $method->getId(),
            'sylius_refund_comment' => '',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects($this->refundPageOf($order), 302, 'Where the refund plugin sends the operator after every refund, this one included.');
        self::assertSame([self::AMOUNT], $this->gateway->refundAmounts, 'The whole amount, at the gateway.');
        self::assertSame(PaymentInterface::STATE_REFUNDED, $this->readBack($payment)->getState(), 'So the page is asked to render a refunded payment.');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful('The page renders; nobody is sent on anywhere.');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Selected order units have been successfully refunded', $html);
        self::assertStringNotContainsString('Order cannot be refunded', $html, 'One message, not two.');
        self::assertStringContainsString(
            sprintf('<strong>%s</strong>', $method->getName()),
            $html,
            'The method that took the money is still named, read off the refunded payment.',
        );
    }

    private function refundPageOf(OrderInterface $order): string
    {
        return sprintf('/admin/orders/%s/refunds', $order->getNumber());
    }

    /** The request clears the entity manager on its way, so what it left is read back rather than trusted in memory. */
    private function readBack(PaymentInterface $payment): PaymentInterface
    {
        $this->manager->clear();
        $read = $this->manager->find($payment::class, $payment->getId());
        self::assertInstanceOf(PaymentInterface::class, $read);

        return $read;
    }

    private function methodOf(PaymentInterface $payment): PaymentMethodInterface
    {
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);

        return $method;
    }

    private function anAdministrator(): AdminUserInterface
    {
        $container = self::getContainer();

        /** @var AdminUserInterface $user */
        $user = $container->get('sylius.factory.admin_user')->createNew();
        $user->setEmail(sprintf('ada+%s@example.com', bin2hex(random_bytes(4))));
        $user->setUsername('ada-' . bin2hex(random_bytes(4)));
        $user->setPlainPassword('not-checked');
        $user->setEnabled(true);
        $user->setLocaleCode('en_US');

        $this->manager->persist($user);
        $this->manager->flush();

        return $user;
    }

    protected function paymentRequestManager(): EntityManagerInterface
    {
        return $this->manager;
    }
}
