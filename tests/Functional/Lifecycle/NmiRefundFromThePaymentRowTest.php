<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Lifecycle;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * *Refund* on the payment row of the order page — Sylius's own button, sent as the browser sends it.
 *
 * Its route declares no event, so the controller announces it as an update, never as a refund. Until
 * 1.3.3 the plugin listened for the refund alone, and the payment was marked refunded with the money
 * still taken. These requests go through the route itself rather than dispatching an event by hand,
 * which is what let that pass unnoticed.
 *
 * The refund plugin hides the button but leaves the route, so the request is the same with it and
 * without it; the token is read off the page when the button is there and made in the operator's
 * session when it is not.
 */
final class NmiRefundFromThePaymentRowTest extends WebTestCase
{
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 1299;

    private KernelBrowser $client;

    /** Fresh for every test: the record keeps one row per identifier and type, whoever wrote it. */
    private string $sale;

    private EntityManagerInterface $manager;

    private FakeNmiClient $gateway;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->sale = (string) random_int(12500000000, 12599999999);

        $this->gateway = new FakeNmiClient();
        self::getContainer()->set('jpm_martin_sylius_nmi.gateway.client', $this->gateway);

        $this->manager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->manager->rollback();

        parent::tearDown();
    }

    protected function paymentRequestManager(): EntityManagerInterface
    {
        return $this->manager;
    }

    public function testTheMoneyGoesBackBeforeThePaymentIsMarkedRefunded(): void
    {
        [$order, $payment] = $this->aPaidOrder();
        $this->gateway->willApprove($this->sale);
        $this->client->loginUser($this->anAdministrator(), 'admin');

        $this->refundFromThePaymentRow($order, $payment);

        self::assertResponseRedirects();
        self::assertSame(['void'], $this->gateway->operations, 'NMI was not asked to give the money back.');
        self::assertSame(PaymentInterface::STATE_REFUNDED, $this->reloaded($payment)->getState());

        /** @var NmiTransactionRepositoryInterface $transactions */
        $transactions = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_transaction');
        self::assertNotNull($transactions->findOneByTransactionIdAndType($this->sale, NmiTransactionInterface::TYPE_VOID), 'The void is not recorded.');
    }

    /** *Refused*: the payment still says the money was taken, and the operator is told why. */
    public function testARefusalLeavesThePaymentCompletedAndTellsTheOperator(): void
    {
        [$order, $payment] = $this->aPaidOrder();
        $this->gateway->willFailOn('void', NmiTransportException::fromInconclusiveStatus(504));
        $this->client->loginUser($this->anAdministrator(), 'admin');

        $this->refundFromThePaymentRow($order, $payment);

        self::assertResponseRedirects();
        self::assertSame(['void'], $this->gateway->operations, 'An unanswered void must not become a refund, nor be asked twice.');
        self::assertSame(PaymentInterface::STATE_COMPLETED, $this->reloaded($payment)->getState());

        $session = $this->client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        self::assertSame(['jpm_martin_sylius_nmi.payment.unreachable'], $session->getFlashBag()->peek('error'));
    }

    private function refundFromThePaymentRow(OrderInterface $order, PaymentInterface $payment): void
    {
        $page = $this->client->request('GET', sprintf('/admin/orders/%d', (int) $order->getId()));
        self::assertResponseIsSuccessful('The order page did not open.');

        $action = sprintf('/admin/orders/%d/payments/%d/refund', (int) $order->getId(), (int) $payment->getId());
        $form = $page->filter(sprintf('form[action$="%s"]', $action));
        if (!class_exists('Sylius\\RefundPlugin\\SyliusRefundPlugin')) {
            self::assertCount(1, $form, 'Without the refund plugin, the payment row offers no Refund.');
        }

        $token = 1 === $form->count()
            ? (string) $form->filter('input[name="_csrf_token"]')->attr('value')
            : $this->aTokenInTheOperatorsSession((string) $payment->getId());

        $this->client->request('POST', $action, ['_method' => 'PUT', '_csrf_token' => $token]);
    }

    /** What the button's `csrf_token(payment.id)` would have put on the page. */
    private function aTokenInTheOperatorsSession(string $tokenId): string
    {
        $request = $this->client->getRequest();

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);

        /** @var CsrfTokenManagerInterface $tokens */
        $tokens = self::getContainer()->get('security.csrf.token_manager');
        $token = $tokens->getToken($tokenId)->getValue();

        $requestStack->pop();
        $request->getSession()->save();

        return $token;
    }

    /** @return array{OrderInterface, PaymentInterface} */
    private function aPaidOrder(): array
    {
        // A checked-out order has a customer, and the order page cannot be drawn without one.
        /** @var CustomerInterface $customer */
        $customer = self::getContainer()->get('sylius.factory.customer')->createNew();
        $customer->setEmail(sprintf('refunded+%s@example.com', bin2hex(random_bytes(4))));
        $this->manager->persist($customer);

        $payment = $this->newPaymentRequest(PaymentRequestInterface::STATE_COMPLETED, customer: $customer)->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $payment->setState(PaymentInterface::STATE_COMPLETED);

        /** @var NmiTransactionRecorderInterface $recorder */
        $recorder = self::getContainer()->get('test.jpm_martin_sylius_nmi.recorder.transaction');
        $recorder->record($payment, $this->approved($this->sale), NmiTransactionInterface::TYPE_SALE);

        $order = $payment->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);
        $order->setState(OrderInterface::STATE_NEW);
        $order->setCheckoutState(OrderCheckoutStates::STATE_COMPLETED);
        $order->setCheckoutCompletedAt(new \DateTime());
        $order->setPaymentState(OrderPaymentStates::STATE_PAID);
        $order->setNumber('R' . random_int(100000000, 999999999));
        $order->setTokenValue('nmi_refunded_' . bin2hex(random_bytes(4)));

        $this->manager->flush();

        return [$order, $payment];
    }

    private function anAdministrator(): AdminUserInterface
    {
        /** @var AdminUserInterface $admin */
        $admin = self::getContainer()->get('sylius.factory.admin_user')->createNew();
        $admin->setEmail(sprintf('admin+%s@example.com', bin2hex(random_bytes(4))));
        $admin->setUsername('admin-' . bin2hex(random_bytes(4)));
        $admin->setPlainPassword('not-checked');
        $admin->setEnabled(true);
        $admin->setLocaleCode('en_US');
        $this->manager->persist($admin);
        $this->manager->flush();

        return $admin;
    }

    private function reloaded(PaymentInterface $payment): PaymentInterface
    {
        $id = $payment->getId();
        $this->manager->clear();
        $payment = $this->manager->find(Payment::class, $id);
        self::assertInstanceOf(PaymentInterface::class, $payment);

        return $payment;
    }

    private function approved(string $transactionId): NmiResponse
    {
        return NmiResponse::fromBody(json_encode([
            'object' => 'transaction',
            'id' => $transactionId,
            'amount' => '12.99',
            'currency' => 'USD',
            'status' => 'pendingsettlement',
            'response' => '1',
            'response_text' => 'SUCCESS',
            'response_code' => '100',
        ], \JSON_THROW_ON_ERROR));
    }
}
