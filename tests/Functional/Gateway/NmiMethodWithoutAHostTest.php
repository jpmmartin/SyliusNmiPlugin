<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Gateway;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequest;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * The *method saved before the host existed* scenario.
 *
 * A method stored by an earlier version has keys and no host. Guessing one would send this
 * account's key to a gateway that may not be its own, so nothing is sent at all: the failure
 * names the method and the field, and the payment stays where it was, payable once an operator
 * has filled the field in.
 */
final class NmiMethodWithoutAHostTest extends WebTestCase
{
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 1299;

    private KernelBrowser $client;

    private EntityManagerInterface $manager;

    private FakeNmiClient $gateway;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->client->catchExceptions(false);

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->gateway = new FakeNmiClient();
        self::getContainer()->set('jpm_martin_sylius_nmi.gateway.client', $this->gateway);

        $this->manager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->manager->rollback();

        parent::tearDown();
    }

    public function testNothingLeavesTheStoreAndTheFailureNamesTheMethodAndTheField(): void
    {
        $this->gateway->willApprove();
        $paymentRequest = $this->newPaymentRequest();

        $gatewayConfig = $paymentRequest->getMethod()->getGatewayConfig();
        self::assertNotNull($gatewayConfig);
        $config = $gatewayConfig->getConfig();
        unset($config[NmiGatewayFactory::CONFIG_API_BASE_URL]);
        $gatewayConfig->setConfig($config);
        $this->manager->flush();

        try {
            $this->client->request('GET', sprintf('/en_US/payment-request/pay/%s', (string) $paymentRequest->getId()));
            self::fail('A method without a host must not render a card form: there is nowhere to send the token.');
        } catch (\Throwable $failure) {
            self::assertStringContainsString((string) $paymentRequest->getMethod()->getCode(), $failure->getMessage(), 'The failure must name the method an operator has to open.');
            self::assertStringContainsString(NmiGatewayFactory::CONFIG_API_BASE_URL, $failure->getMessage(), 'And the field they have to fill in.');
        }

        self::assertNull($this->gateway->lastOperation, 'Nothing may reach the gateway.');

        /** @var PaymentRequest $reloaded */
        $reloaded = $this->manager->find(PaymentRequest::class, (string) $paymentRequest->getId());
        self::assertSame(PaymentInterface::STATE_NEW, $reloaded->getPayment()->getState(), 'The payment stays payable.');
    }

    protected function paymentRequestManager(): EntityManagerInterface
    {
        return $this->manager;
    }
}
