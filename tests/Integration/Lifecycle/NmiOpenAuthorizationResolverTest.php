<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Integration\Lifecycle;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Lifecycle\NmiOpenAuthorizationResolver;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/** Which authorisation of a payment the store's record still shows open at the gateway. */
final class NmiOpenAuthorizationResolverTest extends KernelTestCase
{
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 10951;

    private EntityManagerInterface $manager;

    private NmiOpenAuthorizationResolver $resolver;

    private string $prefix;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        /** @var NmiTransactionRepositoryInterface $transactions */
        $transactions = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_transaction');
        $this->resolver = new NmiOpenAuthorizationResolver($transactions);
        $this->prefix = (string) random_int(1250000000, 1259999999);

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

    public function testTheNewestAuthorizationIsTheOpenOne(): void
    {
        $payment = $this->aPayment();
        // A declined attempt first, then the approval: both are recorded as authorisations.
        $this->record($payment, $this->id(1), NmiTransactionInterface::TYPE_AUTH);
        $this->record($payment, $this->id(2), NmiTransactionInterface::TYPE_AUTH);

        self::assertSame($this->id(2), $this->resolver->resolve($payment)?->getTransactionId());
    }

    public function testAVoidedAuthorizationIsNotOpen(): void
    {
        $payment = $this->aPayment();
        $this->record($payment, $this->id(3), NmiTransactionInterface::TYPE_AUTH);
        $this->record($payment, $this->id(3), NmiTransactionInterface::TYPE_VOID);

        self::assertNull($this->resolver->resolve($payment));
    }

    public function testACapturedAuthorizationIsNotOpen(): void
    {
        $payment = $this->aPayment();
        $this->record($payment, $this->id(4), NmiTransactionInterface::TYPE_AUTH);
        $this->record($payment, $this->id(4), NmiTransactionInterface::TYPE_CAPTURE);

        self::assertNull($this->resolver->resolve($payment));
    }

    public function testAPaymentThatWasChargedHasNoAuthorization(): void
    {
        $payment = $this->aPayment();
        $this->record($payment, $this->id(5), NmiTransactionInterface::TYPE_SALE);

        self::assertNull($this->resolver->resolve($payment));
    }

    /** Identifiers of this test's own, because the record keeps one row per identifier and type. */
    private function id(int $n): string
    {
        return $this->prefix . $n;
    }

    private function aPayment(): PaymentInterface
    {
        $payment = $this->newPaymentRequest(useAuthorize: true)->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);

        return $payment;
    }

    private function record(PaymentInterface $payment, string $transactionId, string $type): void
    {
        /** @var NmiTransactionRecorderInterface $recorder */
        $recorder = self::getContainer()->get('test.jpm_martin_sylius_nmi.recorder.transaction');
        $recorder->record($payment, NmiResponse::fromBody(json_encode([
            'object' => 'transaction',
            'id' => $transactionId,
            'amount' => '109.51',
            'currency' => 'USD',
            'status' => 'pending',
            'response' => '1',
            'response_text' => 'SUCCESS',
            'response_code' => '100',
        ], \JSON_THROW_ON_ERROR)), $type);
        $this->manager->flush();
    }
}
