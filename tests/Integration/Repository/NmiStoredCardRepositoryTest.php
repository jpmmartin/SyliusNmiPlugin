<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Integration\Repository;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use JpmMartin\SyliusNmiPlugin\Repository\NmiStoredCardRepositoryInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Against a real schema, because a query that compiles is not a query that returns the right rows.
 */
final class NmiStoredCardRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $manager;

    private NmiStoredCardRepositoryInterface $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var EntityManagerInterface $manager */
        $manager = $container->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        /** @var NmiStoredCardRepositoryInterface $repository */
        $repository = $container->get('jpm_martin_sylius_nmi.repository.nmi_stored_card');
        $this->repository = $repository;

        $this->manager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->manager->rollback();

        parent::tearDown();
    }

    public function testTheListingPutsTheDefaultFirst(): void
    {
        $customer = $this->aCustomer();
        $method = $this->aPaymentMethod();

        $this->aCard($customer, $method, '1111');
        $this->aCard($customer, $method, '2222');
        $default = $this->aCard($customer, $method, '3333', true);
        $this->manager->flush();

        $cards = $this->repository->findByCustomer($customer);

        self::assertCount(3, $cards);
        self::assertSame($default->getId(), $cards[0]->getId(), 'The default card is the one a shopper expects at the top.');
    }

    /** A card stored against one NMI account cannot be charged against another, so the listing narrows. */
    public function testTheListingCanBeNarrowedToOnePaymentMethod(): void
    {
        $customer = $this->aCustomer();
        $boutique = $this->aPaymentMethod();
        $outlet = $this->aPaymentMethod();

        $this->aCard($customer, $boutique, '1111');
        $this->aCard($customer, $outlet, '2222');
        $this->manager->flush();

        self::assertCount(2, $this->repository->findByCustomer($customer));
        self::assertCount(1, $this->repository->findByCustomer($customer, $boutique));
    }

    public function testOneCardIsFoundOnlyByItsOwner(): void
    {
        $ada = $this->aCustomer();
        $grace = $this->aCustomer();
        $method = $this->aPaymentMethod();

        $card = $this->aCard($ada, $method, '1111');
        $this->manager->flush();

        self::assertNotNull($this->repository->findOneByCustomer((int) $card->getId(), $ada));
        self::assertNull(
            $this->repository->findOneByCustomer((int) $card->getId(), $grace),
            "Reaching another customer's card must fail in the query, not in a check the caller might forget.",
        );
    }

    public function testTheDuplicateLookupMatchesOnAllSixThings(): void
    {
        $customer = $this->aCustomer();
        $method = $this->aPaymentMethod();
        $other = $this->aPaymentMethod();

        $this->aCard($customer, $method, '1111');
        $this->manager->flush();

        $match = fn (PaymentMethodInterface $m, string $brand, string $four, int $month, int $year): ?NmiStoredCardInterface => $this->repository->findOneDuplicate($customer, $m, $brand, $four, $month, $year);

        self::assertNotNull($match($method, 'visa', '1111', 10, 2030), 'The same card on the same account is a duplicate.');
        self::assertNull($match($other, 'visa', '1111', 10, 2030), 'The same card on another gateway account is not.');
        self::assertNull($match($method, 'mastercard', '1111', 10, 2030), 'A different brand is a different card.');
        self::assertNull($match($method, 'visa', '2222', 10, 2030), 'Different digits are a different card.');
        self::assertNull($match($method, 'visa', '1111', 11, 2030), 'A renewed card has a different expiry.');
        self::assertNull($match($method, 'visa', '1111', 10, 2031), 'And so does one renewed into another year.');
    }

    /**
     * The gateway spells the brand `Visa` and the browser spells it `visa`, and the duplicate key
     * is built from one and compared against the other. A plain equality would match on MySQL's
     * default collation and miss on PostgreSQL — the same card stored twice on one engine only.
     */
    public function testTheDuplicateLookupDoesNotCareHowTheBrandIsSpelled(): void
    {
        $customer = $this->aCustomer();
        $method = $this->aPaymentMethod();

        $card = $this->aCard($customer, $method, '1111');
        $card->setBrand('Visa');
        $this->manager->flush();

        self::assertNotNull($this->repository->findOneDuplicate($customer, $method, 'visa', '1111', 10, 2030));
        self::assertNotNull($this->repository->findOneDuplicate($customer, $method, 'VISA', '1111', 10, 2030));
    }

    private function aCustomer(): CustomerInterface
    {
        /** @var CustomerInterface $customer */
        $customer = self::getContainer()->get('sylius.factory.customer')->createNew();
        $customer->setEmail(sprintf('card+%s@example.com', bin2hex(random_bytes(4))));
        $this->manager->persist($customer);

        return $customer;
    }

    private function aPaymentMethod(): PaymentMethodInterface
    {
        $container = self::getContainer();

        $gatewayConfig = $container->get('sylius.factory.gateway_config')->createNew();
        $gatewayConfig->setGatewayName(NmiGatewayFactory::NAME);
        $gatewayConfig->setFactoryName(NmiGatewayFactory::NAME);
        $gatewayConfig->setUsePayum(false);
        $gatewayConfig->setConfig([NmiGatewayFactory::CONFIG_SECURITY_KEY => 'sec-repo']);
        $this->manager->persist($gatewayConfig);

        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $container->get('sylius.factory.payment_method')->createNew();
        $paymentMethod->setCode('nmi_' . bin2hex(random_bytes(4)));
        $paymentMethod->setCurrentLocale('en_US');
        $paymentMethod->setFallbackLocale('en_US');
        $paymentMethod->setName('Card');
        $paymentMethod->setGatewayConfig($gatewayConfig);
        $this->manager->persist($paymentMethod);

        return $paymentMethod;
    }

    private function aCard(
        CustomerInterface $customer,
        PaymentMethodInterface $paymentMethod,
        string $lastFour,
        bool $default = false,
    ): NmiStoredCardInterface {
        /** @var NmiStoredCardInterface $card */
        $card = self::getContainer()->get('jpm_martin_sylius_nmi.factory.nmi_stored_card')->createNew();
        $card->setCustomer($customer);
        $card->setPaymentMethod($paymentMethod);
        $card->setVaultId('vault-' . $lastFour);
        $card->setBillingId('billing-' . $lastFour);
        $card->setBrand('visa');
        $card->setLastFour($lastFour);
        $card->setExpiryMonth(10);
        $card->setExpiryYear(2030);
        $card->setDefault($default);
        $this->manager->persist($card);

        return $card;
    }
}
