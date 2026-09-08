<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Integration\Gateway;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Encryption\EncrypterInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What a stored card looks like on disk.
 *
 * The three gateway identifiers are credentials and must be unreadable; the four display fields
 * must not be, because the account listing and the duplicate check query them and decrypting every
 * row to render a page is not a design. Both halves are asserted, and both are read straight from
 * the row: the ORM decrypts on load, so reading the entity back would prove nothing at all.
 */
final class NmiStoredCardAtRestTest extends KernelTestCase
{
    private const VAULT_ID = 'vault-1929110340';

    private const BILLING_ID = 'billing-349429273';

    private const TRANSACTION_ID = 'tx-12518160386';

    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->manager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->manager->rollback();

        parent::tearDown();
    }

    public function testTheGatewayIdentifiersAreUnreadableInTheRow(): void
    {
        $row = $this->storedRow();

        foreach (['vault_id' => self::VAULT_ID, 'billing_id' => self::BILLING_ID, 'vaulting_transaction_id' => self::TRANSACTION_ID] as $column => $value) {
            self::assertStringNotContainsString($value, (string) $row[$column], sprintf('%s is readable.', $column));
            // "Does not contain it" would also pass on an empty or mangled column, so the stronger
            // claim is made instead: what is there is ciphertext the platform's encrypter produced.
            self::assertStringEndsWith(
                EncrypterInterface::ENCRYPTION_SUFFIX,
                (string) $row[$column],
                sprintf('%s is not encrypted — it is merely not the value we looked for.', $column),
            );
        }
    }

    public function testTheFieldsTheStoreHasToQueryAreInTheClear(): void
    {
        $row = $this->storedRow();

        self::assertSame('1111', $row['last_four']);
        self::assertSame('visa', $row['brand']);
        self::assertSame(10, (int) $row['expiry_month']);
        self::assertSame(2025, (int) $row['expiry_year']);
    }

    /** And the round trip: what the store reads back is what it stored. */
    public function testTheIdentifiersComeBackReadableThroughTheOrm(): void
    {
        $card = $this->aStoredCard();
        $this->manager->flush();
        $this->manager->clear();

        /** @var NmiStoredCardInterface $reloaded */
        $reloaded = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_stored_card')->find($card->getId());

        self::assertSame(self::VAULT_ID, $reloaded->getVaultId());
        self::assertSame(self::BILLING_ID, $reloaded->getBillingId());
        self::assertSame(self::TRANSACTION_ID, $reloaded->getVaultingTransactionId());
    }

    /** @return array<string, mixed> */
    private function storedRow(): array
    {
        $card = $this->aStoredCard();
        $this->manager->flush();

        /** @var array<string, mixed> $row */
        $row = $this->manager->getConnection()->fetchAssociative(
            'SELECT vault_id, billing_id, vaulting_transaction_id, brand, last_four, expiry_month, expiry_year
             FROM jpm_martin_sylius_nmi_stored_card WHERE id = :id',
            ['id' => $card->getId()],
        );

        return $row;
    }

    private function aStoredCard(): NmiStoredCardInterface
    {
        $container = self::getContainer();

        /** @var CustomerInterface $customer */
        $customer = $container->get('sylius.factory.customer')->createNew();
        $customer->setEmail(sprintf('ada+%s@example.com', bin2hex(random_bytes(4))));
        $this->manager->persist($customer);

        $gatewayConfig = $container->get('sylius.factory.gateway_config')->createNew();
        $gatewayConfig->setGatewayName(NmiGatewayFactory::NAME);
        $gatewayConfig->setFactoryName(NmiGatewayFactory::NAME);
        $gatewayConfig->setUsePayum(false);
        $gatewayConfig->setConfig([NmiGatewayFactory::CONFIG_SECURITY_KEY => 'sec-at-rest']);
        $this->manager->persist($gatewayConfig);

        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $container->get('sylius.factory.payment_method')->createNew();
        $paymentMethod->setCode('nmi_card_' . bin2hex(random_bytes(4)));
        $paymentMethod->setCurrentLocale('en_US');
        $paymentMethod->setFallbackLocale('en_US');
        $paymentMethod->setName('Card');
        $paymentMethod->setGatewayConfig($gatewayConfig);
        $this->manager->persist($paymentMethod);

        /** @var NmiStoredCardInterface $card */
        $card = $container->get('jpm_martin_sylius_nmi.factory.nmi_stored_card')->createNew();
        $card->setCustomer($customer);
        $card->setPaymentMethod($paymentMethod);
        $card->setVaultId(self::VAULT_ID);
        $card->setBillingId(self::BILLING_ID);
        $card->setVaultingTransactionId(self::TRANSACTION_ID);
        $card->setBrand('visa');
        $card->setLastFour('1111');
        $card->setExpiryMonth(10);
        $card->setExpiryYear(2025);
        $card->setDefault(true);
        $this->manager->persist($card);

        return $card;
    }
}
