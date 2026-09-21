<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Integration\Repository;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiCardOnFileInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiCardOnFileRepositoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * Which card a payment holds.
 *
 * "Holds" means not yet released. A closed card is still held — the charge has to be able to say
 * it was refused *because* the card is closed, which it could not if the repository had already
 * pretended the card was not there.
 */
final class NmiCardOnFileRepositoryTest extends KernelTestCase
{
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-repository';

    private const SECURITY_KEY = 'sec-private-repository';

    private const AMOUNT = 10951;

    private EntityManagerInterface $manager;

    private NmiCardOnFileRepositoryInterface $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        /** @var NmiCardOnFileRepositoryInterface $repository */
        $repository = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_card_on_file');
        $this->repository = $repository;

        $this->manager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->manager->rollback();

        parent::tearDown();
    }

    public function testAPaymentHoldsTheCardPutOnFileForIt(): void
    {
        [$payment, $card] = $this->aPaymentHoldingACard();

        self::assertSame($card->getId(), $this->repository->findHeldBy($payment)?->getId());
    }

    public function testAPaymentWithNoCardOnFileHoldsNothing(): void
    {
        [$payment] = $this->aPaymentHoldingACard(putOnFile: false);

        self::assertNull($this->repository->findHeldBy($payment));
    }

    public function testAReleasedCardIsNoLongerHeld(): void
    {
        [$payment, $card] = $this->aPaymentHoldingACard();
        $card->setReleasedAt(new \DateTimeImmutable());
        $this->manager->flush();

        self::assertNull($this->repository->findHeldBy($payment));
        self::assertNull($this->repository->findHeldByForUpdate($payment));
    }

    /** Returned, and readable as unusable, so the charge can name the reason. */
    public function testAClosedCardIsStillHeldButNotUsable(): void
    {
        [$payment, $card] = $this->aPaymentHoldingACard();
        $card->setStatus(NmiCardOnFileInterface::STATUS_CLOSED);
        $this->manager->flush();
        $this->manager->clear();

        /** @var PaymentInterface $reloadedPayment */
        $reloadedPayment = $this->manager->find($payment::class, $payment->getId());
        $held = $this->repository->findHeldBy($reloadedPayment);

        self::assertNotNull($held);
        self::assertFalse($held->isUsable());
    }

    /**
     * The platform appends `ORDER BY` to every DQL query through a SQL walker, and a lock clause
     * the walker wrote an order after would be refused by the engine. This is the read the charge
     * takes its lock with, run for real, inside a transaction as the charge runs it.
     */
    public function testTheLockingReadRunsOnThisEngineAndFindsTheCard(): void
    {
        [$payment, $card] = $this->aPaymentHoldingACard();

        self::assertSame($card->getId(), $this->repository->findHeldByForUpdate($payment)?->getId());
    }

    public function testTheCardUpdaterFindsOnlyUnreleasedCardsOfItsMethod(): void
    {
        [$payment, $held] = $this->aPaymentHoldingACard();
        /** @var PaymentMethodInterface $method */
        $method = $payment->getMethod();

        [, $released] = $this->aPaymentHoldingACard(paymentMethod: $method);
        $released->setReleasedAt(new \DateTimeImmutable());

        [, $elsewhere] = $this->aPaymentHoldingACard();
        $this->manager->flush();

        $found = array_map(static fn (NmiCardOnFileInterface $card): ?int => $card->getId(), $this->repository->findHeldUnder($method));

        self::assertSame([$held->getId()], $found);
        self::assertNotContains($elsewhere->getId(), $found);
    }

    /** @return array{PaymentInterface, NmiCardOnFileInterface} */
    private function aPaymentHoldingACard(bool $putOnFile = true, ?PaymentMethodInterface $paymentMethod = null): array
    {
        $paymentRequest = $this->newPaymentRequest(paymentMethod: $paymentMethod);
        /** @var PaymentInterface $payment */
        $payment = $paymentRequest->getPayment();
        /** @var PaymentMethodInterface $method */
        $method = $paymentRequest->getMethod();

        /** @var NmiCardOnFileInterface $card */
        $card = self::getContainer()->get('jpm_martin_sylius_nmi.factory.nmi_card_on_file')->createNew();
        $card->setPayment($payment);
        $card->setPaymentMethod($method);
        $card->setVaultId('vault-' . bin2hex(random_bytes(4)));
        $card->setInitialTransactionId('tx-' . bin2hex(random_bytes(4)));

        if ($putOnFile) {
            $this->manager->persist($card);
            $this->manager->flush();
        }

        return [$payment, $card];
    }

    protected function paymentRequestManager(): EntityManagerInterface
    {
        return $this->manager;
    }
}
