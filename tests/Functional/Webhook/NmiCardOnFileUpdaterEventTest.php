<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Webhook;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Command\NotifyCardholder;
use JpmMartin\SyliusNmiPlugin\Entity\NmiCardOnFile;
use JpmMartin\SyliusNmiPlugin\Entity\NmiCardOnFileInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCard;
use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * *Card updates reach cards on file*, through the same signed summaries the saved cards are updated
 * by: a closed account marks the card on file closed, and a renewal brings its expiry and number up
 * to date — without the email a saved card's holder may get, because nobody saved this one.
 */
final class NmiCardOnFileUpdaterEventTest extends WebTestCase
{
    use BuildsAnNmiWebhookDelivery;

    private KernelBrowser $client;

    private EntityManagerInterface $manager;

    private string $code;

    private string $sale;

    /** Fresh each run: this test commits, as the saved-card updater test does. */
    private string $vaultId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->code = 'nmi_cof_acu_' . bin2hex(random_bytes(4));
        $this->sale = (string) random_int(10_000_000_000, 99_999_999_999);
        $this->vaultId = 'vault-cof-' . bin2hex(random_bytes(4));

        $this->queue()->reset();
    }

    protected function tearDown(): void
    {
        $this->manager->getConnection()->executeStatement(
            'DELETE FROM jpm_martin_sylius_nmi_received_event WHERE payment_method_code = :code',
            ['code' => $this->code],
        );

        parent::tearDown();
    }

    /** *The account behind a card on file was closed.* And, with the email on, nobody is emailed. */
    public function testAClosedAccountMarksTheCardOnFileClosedWithoutEmailingAnybody(): void
    {
        $card = $this->aCardOnFile(emailCardholder: true);

        $this->deliverEvent('acu.summary.closedaccount', 'cof-closed', [
            'vault_count_updated_closed_account' => 1,
            'vault_updates' => [$this->entry($this->vaultId)],
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $updated = $this->refreshed($card);
        self::assertSame(NmiCardOnFileInterface::STATUS_CLOSED, $updated->getStatus());
        self::assertFalse($updated->isUsable());
        self::assertSame([], $this->queuedCardholderEmails());
    }

    /** *A card on file was renewed* — with a new number, so the digits move with the expiry. */
    public function testARenewalBringsTheExpiryAndTheNumberUpToDate(): void
    {
        $card = $this->aCardOnFile(expiryYear: 2020);
        self::assertTrue($card->isExpired(), 'It starts expired, which is the point of the scenario.');

        $this->deliverEvent('acu.summary.automaticallyupdated', 'cof-renewed', [
            'vault_updated_cards' => [$this->entry($this->vaultId, '400000******0077', '01/50')],
        ]);

        $updated = $this->refreshed($card);
        self::assertSame('0077', $updated->getLastFour());
        self::assertSame(1, $updated->getExpiryMonth());
        self::assertSame(2050, $updated->getExpiryYear());
        self::assertFalse($updated->isExpired());
        self::assertTrue($updated->isUsable());
    }

    /** *A summary naming both kinds of card*, and one this store does not know. */
    public function testASummaryNamingBothKindsUpdatesBothAndTheUnknownStopsNeither(): void
    {
        $cardOnFile = $this->aCardOnFile();
        $method = $cardOnFile->getPaymentMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $savedVaultId = 'vault-saved-' . bin2hex(random_bytes(4));
        $saved = $this->aSavedCard($method, $savedVaultId);

        $this->deliverEvent('acu.summary.closedaccount', 'cof-mixed', [
            'vault_updates' => [
                $this->entry('vault-not-this-stores'),
                $this->entry($savedVaultId),
                $this->entry($this->vaultId),
            ],
        ]);

        self::assertSame(NmiCardOnFileInterface::STATUS_CLOSED, $this->refreshed($cardOnFile)->getStatus());
        $this->manager->clear();
        $savedNow = $this->manager->find(NmiStoredCard::class, $saved->getId());
        self::assertInstanceOf(NmiStoredCardInterface::class, $savedNow);
        self::assertSame(NmiStoredCardInterface::STATUS_CLOSED, $savedNow->getStatus());
    }

    private function aCardOnFile(int $expiryYear = 2031, bool $emailCardholder = false): NmiCardOnFileInterface
    {
        $payment = $this->aPaymentIn(PaymentInterface::STATE_PROCESSING, $emailCardholder);
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);

        /** @var NmiCardOnFileInterface $card */
        $card = self::getContainer()->get('jpm_martin_sylius_nmi.factory.nmi_card_on_file')->createNew();
        $card->setPayment($payment);
        $card->setPaymentMethod($method);
        $card->setVaultId($this->vaultId);
        $card->setInitialTransactionId('12584742193');
        $card->setBrand('Visa');
        $card->setLastFour('1111');
        $card->setExpiryMonth(10);
        $card->setExpiryYear($expiryYear);
        $this->manager->persist($card);
        $this->manager->flush();

        return $card;
    }

    private function aSavedCard(PaymentMethodInterface $method, string $vaultId): NmiStoredCardInterface
    {
        $customer = new Customer();
        $customer->setEmail(sprintf('ada+%s@example.com', bin2hex(random_bytes(4))));
        $this->manager->persist($customer);

        /** @var NmiStoredCardInterface $card */
        $card = self::getContainer()->get('jpm_martin_sylius_nmi.factory.nmi_stored_card')->createNew();
        $card->setCustomer($customer);
        $card->setPaymentMethod($method);
        $card->setVaultId($vaultId);
        $card->setBrand('Visa');
        $card->setLastFour('4242');
        $card->setExpiryMonth(10);
        $card->setExpiryYear(2031);
        $card->setDefault(true);
        $this->manager->persist($card);
        $this->manager->flush();

        return $card;
    }

    /** @return array<string, string> */
    private function entry(string $vaultId, string $number = '400000******0002', string $expiry = '11/70'): array
    {
        return ['customer_vault_id' => $vaultId, 'cc_number' => $number, 'cc_exp' => $expiry];
    }

    private function refreshed(NmiCardOnFileInterface $card): NmiCardOnFileInterface
    {
        $this->manager->clear();

        /** @var NmiCardOnFileInterface $fresh */
        $fresh = $this->manager->find(NmiCardOnFile::class, $card->getId());

        return $fresh;
    }

    /** @return list<NotifyCardholder> */
    private function queuedCardholderEmails(): array
    {
        $emails = [];
        foreach ($this->queue()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof NotifyCardholder) {
                $emails[] = $message;
            }
        }

        return $emails;
    }

    private function queue(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.main');

        return $transport;
    }
}
