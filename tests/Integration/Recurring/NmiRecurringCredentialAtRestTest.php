<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Integration\Recurring;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Encryption\EncrypterInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * What a recurring credential looks like on disk.
 *
 * The promise the other two card records make, kept by its own encrypter: the vault reference, the
 * billing identifier and the transaction that first stored the card are credentials and must be
 * unreadable in the row. Read straight from the row, because the ORM decrypts on load and reading
 * the entity back would prove nothing.
 */
final class NmiRecurringCredentialAtRestTest extends KernelTestCase
{
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-recurring-at-rest';

    private const SECURITY_KEY = 'sec-private-recurring-at-rest';

    private const AMOUNT = 10951;

    private const VAULT_ID = 'vault-1256465022';

    private const BILLING_ID = 'billing-875248694';

    private const INITIAL_TRANSACTION_ID = 'tx-12584742193';

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

        foreach (['vault_id' => self::VAULT_ID, 'billing_id' => self::BILLING_ID, 'initial_transaction_id' => self::INITIAL_TRANSACTION_ID] as $column => $value) {
            self::assertStringNotContainsString($value, (string) $row[$column], sprintf('%s is readable.', $column));
            // Not merely absent: what is there is ciphertext the platform's encrypter produced.
            self::assertStringEndsWith(
                EncrypterInterface::ENCRYPTION_SUFFIX,
                (string) $row[$column],
                sprintf('%s is not encrypted — it is merely not the value we looked for.', $column),
            );
        }
    }

    public function testWhatAReceiptPrintsIsInTheClear(): void
    {
        $row = $this->storedRow();

        self::assertSame('Visa', $row['brand']);
        self::assertSame('1111', $row['last_four']);
        self::assertSame(10, (int) $row['expiry_month']);
        self::assertSame(2031, (int) $row['expiry_year']);
    }

    public function testTheIdentifiersComeBackReadableThroughTheOrm(): void
    {
        $credential = $this->aCredential();
        $this->manager->flush();
        $this->manager->clear();

        /** @var NmiRecurringCredentialInterface $reloaded */
        $reloaded = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_recurring_credential')->find($credential->getId());

        self::assertSame(self::VAULT_ID, $reloaded->getVaultId());
        self::assertSame(self::BILLING_ID, $reloaded->getBillingId());
        self::assertSame(self::INITIAL_TRANSACTION_ID, $reloaded->getInitialTransactionId());
    }

    /** @return array<string, mixed> */
    private function storedRow(): array
    {
        $credential = $this->aCredential();
        $this->manager->flush();

        /** @var array<string, mixed> $row */
        $row = $this->manager->getConnection()->fetchAssociative(
            'SELECT vault_id, billing_id, initial_transaction_id, brand, last_four, expiry_month, expiry_year
             FROM jpm_martin_sylius_nmi_recurring_credential WHERE id = :id',
            ['id' => $credential->getId()],
        );

        return $row;
    }

    private function aCredential(): NmiRecurringCredentialInterface
    {
        /** @var CustomerInterface $customer */
        $customer = self::getContainer()->get('sylius.factory.customer')->createNew();
        $customer->setEmail(sprintf('at-rest+%s@example.com', bin2hex(random_bytes(4))));
        $this->manager->persist($customer);

        $paymentRequest = $this->newPaymentRequest(customer: $customer);
        /** @var PaymentInterface $payment */
        $payment = $paymentRequest->getPayment();
        /** @var PaymentMethodInterface $method */
        $method = $paymentRequest->getMethod();

        /** @var NmiRecurringCredentialInterface $credential */
        $credential = self::getContainer()->get('jpm_martin_sylius_nmi.factory.nmi_recurring_credential')->createNew();
        $credential->setInitialPayment($payment);
        $credential->setCustomer($customer);
        $credential->setPaymentMethod($method);
        $credential->setVaultId(self::VAULT_ID);
        $credential->setBillingId(self::BILLING_ID);
        $credential->setInitialTransactionId(self::INITIAL_TRANSACTION_ID);
        $credential->setBrand('Visa');
        $credential->setLastFour('1111');
        $credential->setExpiryMonth(10);
        $credential->setExpiryYear(2031);
        $this->manager->persist($credential);

        return $credential;
    }

    protected function paymentRequestManager(): EntityManagerInterface
    {
        return $this->manager;
    }
}
