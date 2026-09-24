<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Integration\Recurring;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\CardOnFile\NmiChargeOutcome;
use JpmMartin\SyliusNmiPlugin\CommandHandler\ChargeRecurringCredentialHandler;
use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiDeclinedException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorder;
use JpmMartin\SyliusNmiPlugin\Recurring\NmiRecurringChargerInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;
use Tests\JpmMartin\SyliusNmiPlugin\Support\NmiHost;

/**
 * Charging a recurring credential from the store's own code, with nobody present — run from a kernel
 * with no request and no security token at all, which is where a message worker renewing a
 * subscription runs.
 */
final class NmiRecurringChargeTest extends KernelTestCase
{
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 10951;

    private const FIRST_TRANSACTION = '12592792816';

    private EntityManagerInterface $manager;

    private FakeNmiClient $gateway;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var EntityManagerInterface $manager */
        $manager = $container->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->gateway = new FakeNmiClient();
        $container->set('jpm_martin_sylius_nmi.gateway.client', $this->gateway);

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

    /** *A renewal charged from a worker.* */
    public function testARenewalIsChargedWithNobodySignedInAnywhere(): void
    {
        self::assertNull(self::getContainer()->get('security.token_storage')->getToken(), 'The test would prove nothing with somebody signed in.');
        $credential = $this->aCredential();
        $renewal = $this->aRenewalOf($credential);
        $this->gateway->willApprove('12592801001', '109.51');

        $outcome = $this->charger()->charge($renewal, $credential);

        self::assertTrue($outcome->isApproved(), sprintf('Expected approval, got %s: %s', $outcome->status, $outcome->messageKey));
        self::assertSame('12592801001', $outcome->transactionId);
        self::assertSame(ChargeRecurringCredentialHandler::CHARGED, $outcome->messageKey);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $renewal->getState());
        self::assertSame(OrderPaymentStates::STATE_PAID, $renewal->getOrder()?->getPaymentState());
        $this->assertRecordedAgainst($renewal, '12592801001', NmiTransactionInterface::TYPE_SALE);
        self::assertFalse($credential->isReleased(), 'A charge must never let the credential go.');
        self::assertSame([], $this->queue()->getSent(), 'Nothing may be queued for removal at the gateway.');
    }

    /** The held payment that opened the credential waits in processing, and is charged from there. */
    public function testThePaymentThatOpenedTheCredentialIsChargedFromProcessing(): void
    {
        $credential = $this->aCredential();
        $held = $credential->getInitialPayment();
        self::assertInstanceOf(PaymentInterface::class, $held);
        $this->gateway->willApprove('12592801002', '109.51');

        $outcome = $this->charger()->charge($held, $credential);

        self::assertTrue($outcome->isApproved());
        self::assertSame(PaymentInterface::STATE_COMPLETED, $held->getState());
        self::assertFalse($credential->isReleased());
    }

    /** *A different amount.* */
    public function testTheAmountIsTheRenewalsOwnEvenWhenItDiffersFromTheFirst(): void
    {
        $credential = $this->aCredential();
        $renewal = $this->aRenewalOf($credential, amount: 2499);
        $this->gateway->willApprove('12592801003', '24.99');

        $this->charger()->charge($renewal, $credential);

        $charge = $this->gateway->lastCharge;
        self::assertSame(['sale'], $this->gateway->operations);
        self::assertSame(2499, $charge?->amount);
        self::assertSame('USD', $charge?->currencyCode);
    }

    /** *A decline.* */
    public function testADeclineLeavesThePaymentWaitingAndTheCredentialUsable(): void
    {
        $credential = $this->aCredential();
        $renewal = $this->aRenewalOf($credential);
        $this->gateway->willFail(new NmiDeclinedException(NmiResponse::fromBody((string) json_encode([
            'object' => 'transaction', 'id' => '12592801005', 'type' => 'cc', 'amount' => '109.51', 'currency' => 'USD',
            'response' => '2', 'response_text' => 'Insufficient funds', 'response_code' => '202',
        ]))));

        $outcome = $this->charger()->charge($renewal, $credential);

        self::assertSame(NmiChargeOutcome::DECLINED, $outcome->status);
        self::assertSame(ChargeRecurringCredentialHandler::DECLINED, $outcome->messageKey);
        self::assertSame('Insufficient funds', $outcome->reason);
        self::assertSame(202, $outcome->code);
        self::assertSame(PaymentInterface::STATE_NEW, $renewal->getState());
        self::assertFalse($credential->isReleased());
        self::assertTrue($credential->isUsable());
        $this->assertRecordedAgainst($renewal, '12592801005', NmiTransactionInterface::TYPE_SALE);
        self::assertSame(ChargeRecurringCredentialHandler::DECLINED, $renewal->getDetails()[NmiTransactionRecorder::REFUSAL_DETAILS_KEY]['message_key'] ?? null);
    }

    /** *No answer.* */
    public function testNoAnswerIsUnknownNotDeclined(): void
    {
        $credential = $this->aCredential();
        $renewal = $this->aRenewalOf($credential);
        $this->gateway->willFail(new NmiTransportException('Connection timed out'));

        $outcome = $this->charger()->charge($renewal, $credential);

        self::assertSame(NmiChargeOutcome::UNKNOWN, $outcome->status);
        self::assertSame(ChargeRecurringCredentialHandler::UNKNOWN, $outcome->messageKey);
        self::assertSame(PaymentInterface::STATE_NEW, $renewal->getState());
        self::assertNotSame(OrderPaymentStates::STATE_PAID, $renewal->getOrder()?->getPaymentState());
        self::assertFalse($credential->isReleased());
    }

    /** *A credential that was let go.* */
    public function testACredentialLetGoIsRefused(): void
    {
        $credential = $this->aCredential();
        $credential->setReleasedAt(new \DateTimeImmutable());
        $this->manager->flush();

        $this->assertRefusedUntouched($this->aRenewalOf($credential), $credential, ChargeRecurringCredentialHandler::RELEASED);
    }

    /** *A closed card.* */
    public function testAClosedCardIsRefused(): void
    {
        $credential = $this->aCredential();
        $credential->setStatus(NmiRecurringCredentialInterface::STATUS_CLOSED);
        $this->manager->flush();

        $this->assertRefusedUntouched($this->aRenewalOf($credential), $credential, ChargeRecurringCredentialHandler::CLOSED);
    }

    /** *An expired card.* */
    public function testAnExpiredCardIsRefused(): void
    {
        $credential = $this->aCredential();
        $credential->setExpiryMonth(1);
        $credential->setExpiryYear(2020);
        $this->manager->flush();

        $this->assertRefusedUntouched($this->aRenewalOf($credential), $credential, ChargeRecurringCredentialHandler::EXPIRED);
    }

    /** *No first transaction.* */
    public function testACredentialWithNoFirstTransactionIsRefused(): void
    {
        $credential = $this->aCredential();
        $credential->setInitialTransactionId(null);
        $this->manager->flush();

        $this->assertRefusedUntouched($this->aRenewalOf($credential), $credential, ChargeRecurringCredentialHandler::NO_INITIAL_TRANSACTION);
    }

    /** *Another payment method.* */
    public function testAPaymentOnAnotherMethodIsRefused(): void
    {
        $credential = $this->aCredential();
        $renewal = $this->aRenewalOf($credential);
        $renewal->setMethod($this->anotherNmiMethod());
        $this->manager->flush();

        $this->assertRefusedUntouched($renewal, $credential, ChargeRecurringCredentialHandler::OTHER_METHOD);
    }

    /** *A payment already paid or cancelled.* */
    public function testAPaymentAlreadyPaidIsRefused(): void
    {
        $credential = $this->aCredential();
        $renewal = $this->aRenewalOf($credential);
        $renewal->setState(PaymentInterface::STATE_COMPLETED);
        $this->manager->flush();

        $this->assertRefusedUntouched($renewal, $credential, ChargeRecurringCredentialHandler::NOT_WAITING, PaymentInterface::STATE_COMPLETED);
    }

    public function testAPaymentAlreadyCancelledIsRefused(): void
    {
        $credential = $this->aCredential();
        $renewal = $this->aRenewalOf($credential);
        $renewal->setState(PaymentInterface::STATE_CANCELLED);
        $this->manager->flush();

        $this->assertRefusedUntouched($renewal, $credential, ChargeRecurringCredentialHandler::NOT_WAITING, PaymentInterface::STATE_CANCELLED);
    }

    /** A second charge of a renewal already paid is refused: the lock makes the second wait for the first. */
    public function testASecondChargeOfTheSameRenewalIsRefused(): void
    {
        $credential = $this->aCredential();
        $renewal = $this->aRenewalOf($credential);
        $this->gateway->willApprove('12592801006', '109.51');
        self::assertTrue($this->charger()->charge($renewal, $credential)->isApproved());

        $second = $this->charger()->charge($renewal, $credential);

        self::assertSame(NmiChargeOutcome::REFUSED, $second->status);
        self::assertSame(ChargeRecurringCredentialHandler::NOT_WAITING, $second->messageKey);
        self::assertSame(['sale'], $this->gateway->operations, 'The gateway was asked twice.');
    }

    /** The record an operator finds on the renewal names the action, whatever the outcome. */
    public function testTheChargeIsRecordedUnderItsOwnAction(): void
    {
        $credential = $this->aCredential();
        $renewal = $this->aRenewalOf($credential);
        $this->gateway->willApprove('12592801007', '109.51');

        $this->charger()->charge($renewal, $credential);

        $actions = array_map(
            static fn ($request): string => $request->getAction(),
            self::getContainer()->get('sylius.repository.payment_request')->findBy(['payment' => $renewal]),
        );
        self::assertContains(NmiRecurringChargerInterface::ACTION, $actions);
    }

    private function assertRefusedUntouched(
        PaymentInterface $payment,
        NmiRecurringCredentialInterface $credential,
        string $messageKey,
        string $state = PaymentInterface::STATE_NEW,
    ): void {
        $outcome = $this->charger()->charge($payment, $credential);

        self::assertSame(NmiChargeOutcome::REFUSED, $outcome->status);
        self::assertSame($messageKey, $outcome->messageKey);
        self::assertSame([], $this->gateway->operations, 'The gateway was contacted.');
        self::assertSame($state, $payment->getState());
    }

    /**
     * A credential as a checkout leaves one: opened by a held payment waiting in processing, on an NMI
     * method, for a customer, citing its first transaction.
     */
    private function aCredential(): NmiRecurringCredentialInterface
    {
        /** @var CustomerInterface $customer */
        $customer = self::getContainer()->get('sylius.factory.customer')->createNew();
        $customer->setEmail(sprintf('renewals+%s@example.com', bin2hex(random_bytes(4))));
        $this->manager->persist($customer);

        $payment = $this->newPaymentRequest(customer: $customer)->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $payment->setState(PaymentInterface::STATE_PROCESSING);
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);

        /** @var NmiRecurringCredentialInterface $credential */
        $credential = self::getContainer()->get('jpm_martin_sylius_nmi.factory.nmi_recurring_credential')->createNew();
        $credential->setInitialPayment($payment);
        $credential->setCustomer($customer);
        $credential->setPaymentMethod($method);
        $credential->setVaultId('1736036779');
        $credential->setInitialTransactionId(self::FIRST_TRANSACTION);
        $credential->setBrand('visa');
        $credential->setLastFour('1111');
        $credential->setExpiryMonth(10);
        $credential->setExpiryYear(2035);
        $this->manager->persist($credential);
        $this->manager->flush();

        return $credential;
    }

    /** A payment the store created for a renewal: another order, the same method, left new. */
    private function aRenewalOf(NmiRecurringCredentialInterface $credential, int $amount = self::AMOUNT): PaymentInterface
    {
        $method = $credential->getPaymentMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);

        $payment = $this->newPaymentRequest(customer: $credential->getCustomer(), paymentMethod: $method)->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $payment->setAmount($amount);
        $payment->getOrder()?->setNumber('R' . random_int(100000000, 999999999));
        $this->manager->flush();

        return $payment;
    }

    private function anotherNmiMethod(): PaymentMethodInterface
    {
        /** @var GatewayConfigInterface $gatewayConfig */
        $gatewayConfig = self::getContainer()->get('sylius.factory.gateway_config')->createNew();
        $gatewayConfig->setGatewayName(NmiGatewayFactory::NAME);
        $gatewayConfig->setFactoryName(NmiGatewayFactory::NAME);
        $gatewayConfig->setConfig([
            NmiGatewayFactory::CONFIG_TOKENIZATION_KEY => 'tok-other',
            NmiGatewayFactory::CONFIG_SECURITY_KEY => 'sec-other',
            NmiGatewayFactory::CONFIG_API_BASE_URL => NmiHost::forTests(),
        ]);
        $gatewayConfig->setUsePayum(false);

        $method = new PaymentMethod();
        $method->setCode('nmi_other_' . bin2hex(random_bytes(4)));
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName('Other card');
        $method->setGatewayConfig($gatewayConfig);
        $this->manager->persist($gatewayConfig);
        $this->manager->persist($method);
        $this->manager->flush();

        return $method;
    }

    private function charger(): NmiRecurringChargerInterface
    {
        /** @var NmiRecurringChargerInterface $charger */
        $charger = self::getContainer()->get('test.jpm_martin_sylius_nmi.recurring.charger');

        return $charger;
    }

    private function queue(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.main');

        return $transport;
    }

    private function assertRecordedAgainst(PaymentInterface $payment, string $transactionId, string $type): void
    {
        /** @var NmiTransactionRepositoryInterface $transactions */
        $transactions = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_transaction');

        $transaction = $transactions->findOneByAnyTransactionId($transactionId);
        self::assertSame($type, $transaction?->getType());
        self::assertSame($payment->getId(), $transaction?->getPayment()?->getId(), 'Recorded against another payment.');
    }
}
