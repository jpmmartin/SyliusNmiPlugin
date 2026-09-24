<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Integration\Repository;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiRecurringCredentialRepositoryInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * How a store finds a recurring credential again: by the payment that opened it, then by the id it
 * kept. And how the plugin finds the ones it still has to act on.
 */
final class NmiRecurringCredentialRepositoryTest extends KernelTestCase
{
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-recurring';

    private const SECURITY_KEY = 'sec-private-recurring';

    private const AMOUNT = 10951;

    private EntityManagerInterface $manager;

    private NmiRecurringCredentialRepositoryInterface $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        /** @var NmiRecurringCredentialRepositoryInterface $repository */
        $repository = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_recurring_credential');
        $this->repository = $repository;

        $this->manager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->manager->rollback();

        parent::tearDown();
    }

    public function testThePaymentThatOpenedACredentialFindsIt(): void
    {
        [$payment, $credential] = $this->aPaymentThatOpenedACredential();

        self::assertSame($credential, $this->repository->findOpenedBy($payment));
    }

    public function testAPaymentThatOpenedNoneFindsNothing(): void
    {
        [$payment] = $this->aPaymentThatOpenedACredential(opened: false);

        self::assertNull($this->repository->findOpenedBy($payment));
    }

    /** The id is the reference a store keeps, and it survives a fresh read from the database. */
    public function testTheIdAStoreKeptFindsItAgain(): void
    {
        [, $credential] = $this->aPaymentThatOpenedACredential();
        $id = $credential->getId();
        self::assertNotNull($id);
        $this->manager->clear();

        $found = $this->repository->find($id);
        self::assertInstanceOf(NmiRecurringCredentialInterface::class, $found);
        self::assertSame($id, $found->getId());
        self::assertNull($this->repository->find($id + 1000000));
    }

    public function testTheLockingReadRunsOnThisEngineAndFindsTheCredential(): void
    {
        [, $credential] = $this->aPaymentThatOpenedACredential();

        self::assertSame($credential, $this->repository->findForUpdate((int) $credential->getId()));
    }

    /** Let go, it is still found by its payment — so a caller can tell "let go" from "there is none". */
    public function testACredentialLetGoIsStillFoundButNoLongerAmongTheUnreleased(): void
    {
        [$payment, $credential, $customer] = $this->aPaymentThatOpenedACredential();
        $credential->setReleasedAt(new \DateTimeImmutable());
        $this->manager->flush();

        self::assertSame($credential, $this->repository->findOpenedBy($payment));
        self::assertSame([], $this->repository->findUnreleasedOf($customer));
    }

    public function testACustomersUnreleasedCredentialsAreTheirsAlone(): void
    {
        [, $first, $customer] = $this->aPaymentThatOpenedACredential();
        [, $second] = $this->aPaymentThatOpenedACredential(customer: $customer);
        [, $someoneElses] = $this->aPaymentThatOpenedACredential();

        $found = $this->repository->findUnreleasedOf($customer);

        self::assertCount(2, $found);
        self::assertContains($first, $found);
        self::assertContains($second, $found);
        self::assertNotContains($someoneElses, $found);
    }

    public function testTheCardUpdaterFindsOnlyUnreleasedCredentialsOfItsMethod(): void
    {
        [$payment, $credential] = $this->aPaymentThatOpenedACredential();
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        [, $sameMethodLetGo] = $this->aPaymentThatOpenedACredential(paymentMethod: $method);
        $sameMethodLetGo->setReleasedAt(new \DateTimeImmutable());
        [, $otherMethod] = $this->aPaymentThatOpenedACredential();
        $this->manager->flush();

        self::assertSame([$credential], $this->repository->findUnreleasedUnder($method));
        self::assertNotContains($otherMethod, $this->repository->findUnreleasedUnder($method));
    }

    /**
     * @return array{PaymentInterface, NmiRecurringCredentialInterface, CustomerInterface}
     */
    private function aPaymentThatOpenedACredential(
        bool $opened = true,
        ?CustomerInterface $customer = null,
        ?PaymentMethodInterface $paymentMethod = null,
    ): array {
        $customer ??= $this->aCustomer();
        $paymentRequest = $this->newPaymentRequest(paymentMethod: $paymentMethod, customer: $customer);
        /** @var PaymentInterface $payment */
        $payment = $paymentRequest->getPayment();
        /** @var PaymentMethodInterface $method */
        $method = $paymentRequest->getMethod();

        /** @var NmiRecurringCredentialInterface $credential */
        $credential = self::getContainer()->get('jpm_martin_sylius_nmi.factory.nmi_recurring_credential')->createNew();
        $credential->setInitialPayment($payment);
        $credential->setCustomer($customer);
        $credential->setPaymentMethod($method);
        $credential->setVaultId('vault-' . bin2hex(random_bytes(4)));
        $credential->setInitialTransactionId('tx-' . bin2hex(random_bytes(4)));

        if ($opened) {
            $this->manager->persist($credential);
            $this->manager->flush();
        }

        return [$payment, $credential, $customer];
    }

    private function aCustomer(): CustomerInterface
    {
        /** @var CustomerInterface $customer */
        $customer = self::getContainer()->get('sylius.factory.customer')->createNew();
        $customer->setEmail(sprintf('renewals+%s@example.com', bin2hex(random_bytes(4))));
        $this->manager->persist($customer);

        return $customer;
    }

    protected function paymentRequestManager(): EntityManagerInterface
    {
        return $this->manager;
    }
}
