<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Api;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * *A payment is refunded at NMI before it is marked refunded*, through the platform's admin API and a
 * store's own code — the paths that apply the transition directly, with none of the order screen's
 * events. Until 1.3.3 both marked the payment refunded and left the money with the store.
 */
final class NmiAdminApiRefundTest extends WebTestCase
{
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 1299;

    private const PASSWORD = 'an-admin-password';

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

    /** *Refunded through the admin API.* */
    public function testTheMoneyGoesBackBeforeTheApiMarksThePaymentRefunded(): void
    {
        $payment = $this->aCompletedPayment();
        $this->gateway->willApprove($this->sale);

        $this->refund($payment);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame(['void'], $this->gateway->operations, 'NMI was not asked to give the money back.');
        self::assertSame(PaymentInterface::STATE_REFUNDED, $this->reloaded($payment)->getState());
    }

    /** *Refused by NMI*: the admin API answers 422 and the payment still says the money was taken. */
    public function testARefusalIsAnswered422AndLeavesThePaymentCompleted(): void
    {
        $payment = $this->aCompletedPayment();
        $this->gateway->willFailOn('void', NmiTransportException::fromInconclusiveStatus(504));

        $this->refund($payment);

        self::assertSame(422, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame(['void'], $this->gateway->operations);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $this->reloaded($payment)->getState());
    }

    /** *A store's own code* applying the transition is refunded at NMI the same way. */
    public function testAStoresOwnCodeApplyingTheRefundGivesTheMoneyBackToo(): void
    {
        $payment = $this->aCompletedPayment();
        $this->gateway->willApprove($this->sale);

        /** @var StateMachineInterface $stateMachine */
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');
        $stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);
        $this->manager->flush();

        self::assertSame(['void'], $this->gateway->operations);
        self::assertSame(PaymentInterface::STATE_REFUNDED, $this->reloaded($payment)->getState());
    }

    private function refund(PaymentInterface $payment): void
    {
        $this->client->request(
            'PATCH',
            sprintf('/api/v2/admin/payments/%d/refund', (int) $payment->getId()),
            server: [
                'CONTENT_TYPE' => 'application/merge-patch+json',
                'HTTP_ACCEPT' => 'application/ld+json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->anAdministratorsToken(),
            ],
            content: '{}',
        );
    }

    private function anAdministratorsToken(): string
    {
        $email = sprintf('admin+%s@example.com', bin2hex(random_bytes(4)));

        /** @var AdminUserInterface $admin */
        $admin = self::getContainer()->get('sylius.factory.admin_user')->createNew();
        $admin->setEmail($email);
        $admin->setUsername($email);
        $admin->setPlainPassword(self::PASSWORD);
        $admin->setEnabled(true);
        $admin->setLocaleCode('en_US');
        $admin->addRole('ROLE_API_ACCESS');
        $this->manager->persist($admin);
        $this->manager->flush();

        $this->client->request(
            'POST',
            '/api/v2/admin/administrators/token',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode(['email' => $email, 'password' => self::PASSWORD], \JSON_THROW_ON_ERROR),
        );

        $token = json_decode((string) $this->client->getResponse()->getContent(), true)['token'] ?? null;
        self::assertIsString($token, 'No token: ' . (string) $this->client->getResponse()->getContent());

        return $token;
    }

    /** Paid with a sale on record; the API answers with the payment's order by its token. */
    private function aCompletedPayment(): PaymentInterface
    {
        $payment = $this->newPaymentRequest(PaymentRequestInterface::STATE_COMPLETED)->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $payment->setState(PaymentInterface::STATE_COMPLETED);
        $payment->getOrder()?->setPaymentState(OrderPaymentStates::STATE_PAID);
        $payment->getOrder()?->setTokenValue('nmi_admin_api_' . bin2hex(random_bytes(4)));
        $payment->getOrder()?->setNumber('A' . random_int(100000000, 999999999));

        /** @var NmiTransactionRecorderInterface $recorder */
        $recorder = self::getContainer()->get('test.jpm_martin_sylius_nmi.recorder.transaction');
        $recorder->record($payment, $this->approved($this->sale), NmiTransactionInterface::TYPE_SALE);

        $this->manager->flush();

        return $payment;
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
