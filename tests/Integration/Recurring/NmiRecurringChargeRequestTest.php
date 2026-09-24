<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Integration\Recurring;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiAmountFormatter;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiClient;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\StoredCard;
use JpmMartin\SyliusNmiPlugin\Recurring\NmiRecurringChargerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;
use Tests\JpmMartin\SyliusNmiPlugin\Unit\Gateway\Double\RecordingHttpClient;

/**
 * *What the gateway is told* when a recurring credential is charged, read off the request itself:
 * the plugin's real client, from the charger down, answering from a recording double of the network.
 */
final class NmiRecurringChargeRequestTest extends KernelTestCase
{
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 10951;

    private const FIRST_TRANSACTION = '12592792816';

    private EntityManagerInterface $manager;

    private RecordingHttpClient $network;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var EntityManagerInterface $manager */
        $manager = $container->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $psr17 = new Psr17Factory();
        $this->network = new RecordingHttpClient($psr17);
        $container->set('jpm_martin_sylius_nmi.gateway.client', new NmiClient($this->network, $psr17, $psr17, new NmiAmountFormatter()));

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

    public function testTheRequestDeclaresAMerchantInitiatedUseCitingTheFirstTransaction(): void
    {
        $this->network->willAnswer(200, (string) json_encode([
            'object' => 'transaction', 'id' => '12592801004', 'type' => 'cc', 'amount' => '109.51', 'currency' => 'USD',
            'status' => 'pendingsettlement', 'response' => '1', 'response_text' => 'SUCCESS', 'response_code' => '100',
        ]));
        [$renewal, $credential] = $this->aRenewalAndItsCredential();

        // A store that declares the charge its own way, among fields it may legitimately add.
        $outcome = $this->charger()->charge($renewal, $credential, [
            'cit_mit' => ['initiated_by' => 'customer', 'stored_credential_indicator' => 'stored', 'initial_transaction_id' => 'somebody-else'],
            'descriptor' => 'SHOP*RENEWAL',
        ]);

        self::assertTrue($outcome->isApproved(), sprintf('Expected approval, got %s: %s', $outcome->status, $outcome->messageKey));
        $body = $this->network->lastBodyJson();
        self::assertSame('109.51', $body['amount'] ?? null);
        self::assertSame('USD', $body['currency'] ?? null);
        self::assertSame($renewal->getOrder()?->getNumber(), $body['order_details']['id'] ?? null);
        self::assertSame('1736036779', $body['customer_vault']['id'] ?? null);
        self::assertSame([
            'initiated_by' => StoredCard::INITIATED_BY_MERCHANT,
            'stored_credential_indicator' => 'used',
            'initial_transaction_id' => self::FIRST_TRANSACTION,
        ], $body['cit_mit'] ?? null, 'The store\'s declaration must not change the plugin\'s.');
        self::assertArrayNotHasKey('three_ds', $body);
        self::assertArrayNotHasKey('payment_details', $body, 'No token: the card is the stored one.');
        self::assertSame('SHOP*RENEWAL', $body['descriptor'] ?? null);
    }

    /** @return array{PaymentInterface, NmiRecurringCredentialInterface} */
    private function aRenewalAndItsCredential(): array
    {
        /** @var CustomerInterface $customer */
        $customer = self::getContainer()->get('sylius.factory.customer')->createNew();
        $customer->setEmail(sprintf('renewals+%s@example.com', bin2hex(random_bytes(4))));
        $this->manager->persist($customer);

        $first = $this->newPaymentRequest(customer: $customer)->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $first);
        $method = $first->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);

        /** @var NmiRecurringCredentialInterface $credential */
        $credential = self::getContainer()->get('jpm_martin_sylius_nmi.factory.nmi_recurring_credential')->createNew();
        $credential->setInitialPayment($first);
        $credential->setCustomer($customer);
        $credential->setPaymentMethod($method);
        $credential->setVaultId('1736036779');
        $credential->setInitialTransactionId(self::FIRST_TRANSACTION);
        $this->manager->persist($credential);

        $renewal = $this->newPaymentRequest(customer: $customer, paymentMethod: $method)->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $renewal);
        $renewal->getOrder()?->setNumber('R' . random_int(100000000, 999999999));
        $this->manager->flush();

        return [$renewal, $credential];
    }

    private function charger(): NmiRecurringChargerInterface
    {
        /** @var NmiRecurringChargerInterface $charger */
        $charger = self::getContainer()->get('test.jpm_martin_sylius_nmi.recurring.charger');

        return $charger;
    }
}
