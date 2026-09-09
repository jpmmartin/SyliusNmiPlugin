<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Webhook;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Command\NotifyCardholder;
use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCard;
use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemoryTransport;
use Tests\JpmMartin\SyliusNmiPlugin\Double\RecordingLogger;

/**
 * What the card networks reported, arriving as the gateway's daily summaries.
 *
 * **These payloads are built from the gateway's published samples and could not be otherwise.**
 * A card-updater summary is raised by the card networks, not by anything a store can do, so no
 * amount of sandbox work produces one. What the sandbox did settle is the shape: task 1.2
 * established that every entry carries `customer_vault_id` and `billing_id`, and that the expiry
 * comes as `01/50` where the rest of the gateway says `1030`.
 *
 * **The lookup they need is the interesting part.** The vault reference is encrypted with a
 * non-deterministic cipher, so no query can match it — measured, not assumed — and the card is
 * found by decrypting the candidates instead. These tests exercise that path end to end, which is
 * the only way to know it works at all.
 */
final class NmiCardUpdaterEventTest extends WebTestCase
{
    use BuildsAnNmiWebhookDelivery;

    private const VAULT_ID = 'vault-2061222895';

    private KernelBrowser $client;

    private EntityManagerInterface $manager;

    private string $code;

    private string $sale;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->code = 'nmi_acu_' . bin2hex(random_bytes(4));
        $this->sale = (string) random_int(10_000_000_000, 99_999_999_999);

        $this->logger = new RecordingLogger();
        self::getContainer()->set('logger', $this->logger);
    }

    protected function tearDown(): void
    {
        $this->manager->getConnection()->executeStatement(
            'DELETE FROM jpm_martin_sylius_nmi_received_event WHERE payment_method_code = :code',
            ['code' => $this->code],
        );

        parent::tearDown();
    }

    /** *The issuer renewed the card.* */
    public function testARenewalUpdatesTheExpiryAndTheCardBecomesSelectableAgain(): void
    {
        $card = $this->aStoredCard(expiryMonth: 1, expiryYear: 2020);
        self::assertTrue($card->isExpired(), 'It starts expired, which is the point of the scenario.');

        $this->deliverEvent('acu.summary.automaticallyupdated', 'acu-renewed', [
            'vault_count_updated_expiration_dates' => 1,
            'vault_updated_expiration_dates' => [$this->entry(expiry: '11/70')],
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $updated = $this->refreshedCard($card);
        self::assertSame(11, $updated->getExpiryMonth());
        self::assertSame(2070, $updated->getExpiryYear());
        self::assertFalse($updated->isExpired(), 'It stops being shown as expired.');
        self::assertTrue($updated->isUsable(), 'And it is selectable again.');
    }

    /**
     * *The issuer replaced the card number.* The shopper has to recognise the card afterwards, so
     * the digits move with the expiry — otherwise the account would list a card nobody holds.
     */
    public function testAReplacedCardNumberMovesTheStoredDigitsToo(): void
    {
        $card = $this->aStoredCard(lastFour: '1111');

        $this->deliverEvent('acu.summary.automaticallyupdated', 'acu-renumbered', [
            'vault_count_updated_cards' => 1,
            'vault_updated_cards' => [$this->entry(number: '445701******0009', expiry: '01/50')],
        ]);

        $updated = $this->refreshedCard($card);
        self::assertSame('0009', $updated->getLastFour());
        self::assertSame(1, $updated->getExpiryMonth());
        self::assertSame(2050, $updated->getExpiryYear());
        self::assertSame('Visa', $updated->getBrand(), 'A reissue keeps the network, and the summary names none.');
    }

    /** *The issuer closed the account.* */
    public function testAClosedAccountMarksTheCardUnusable(): void
    {
        $card = $this->aStoredCard();

        $this->deliverEvent('acu.summary.closedaccount', 'acu-closed', [
            'vault_count_updated_closed_account' => 1,
            'vault_updates' => [$this->entry()],
        ]);

        $updated = $this->refreshedCard($card);
        self::assertSame(NmiStoredCardInterface::STATUS_CLOSED, $updated->getStatus());
        self::assertFalse($updated->isUsable(), 'It may no longer be offered at checkout.');
    }

    /** *The issuer asks that the customer be contacted.* Flagged, and still usable. */
    public function testAContactCustomerSummaryFlagsTheCardWithoutDisablingIt(): void
    {
        $card = $this->aStoredCard();

        $this->deliverEvent('acu.summary.contactcustomer', 'acu-contact', [
            'vault_count_updated_contact_customer' => 1,
            'vault_updates' => [$this->entry()],
        ]);

        $updated = $this->refreshedCard($card);
        self::assertSame(NmiStoredCardInterface::STATUS_NEEDS_ATTENTION, $updated->getStatus());
        self::assertTrue($updated->isUsable(), 'The issuer said to talk to them, not that the card stopped working.');
    }

    /**
     * *A summary naming several cards.* The unrecognised ones are the majority on a shared
     * account, and one of them must not stop the rest.
     */
    public function testACardThisStoreDoesNotHoldDoesNotStopTheOthers(): void
    {
        $card = $this->aStoredCard(expiryMonth: 1, expiryYear: 2020);

        $this->deliverEvent('acu.summary.automaticallyupdated', 'acu-mixed', [
            'vault_updated_expiration_dates' => [
                ['customer_vault_id' => 'vault-belonging-to-another-store', 'cc_number' => '400000******0002', 'cc_exp' => '12/33'],
                $this->entry(expiry: '11/70'),
                ['customer_vault_id' => 'vault-also-not-ours', 'cc_number' => '520000******0007', 'cc_exp' => '12/33'],
            ],
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(2070, $this->refreshedCard($card)->getExpiryYear());
    }

    /**
     * The parallel arrays about recurring subscriptions are skipped rather than resolved. This
     * plugin creates none, so treating them as unresolvable would make every delivery look like a
     * fault — and the log is what an operator reads to decide whether anything is wrong.
     */
    public function testTheRecurringArraysAreIgnoredEntirely(): void
    {
        $card = $this->aStoredCard(expiryMonth: 1, expiryYear: 2020);

        $this->deliverEvent('acu.summary.automaticallyupdated', 'acu-recurring', [
            'vault_updated_expiration_dates' => [$this->entry(expiry: '11/70')],
            'recurring_count_updated_expiration_dates' => 2,
            'recurring_updated_expiration_dates' => [
                ['subscription_id' => '281474976710725', 'cc_number' => '400000******3223', 'cc_exp' => '11/70'],
                ['subscription_id' => '281474976710726', 'cc_number' => '445701******1123', 'cc_exp' => '01/50'],
            ],
        ]);

        self::assertSame(2070, $this->refreshedCard($card)->getExpiryYear());

        $summary = null;
        foreach ($this->logger->records as $record) {
            if (str_contains($record['message'], 'card updater summary')) {
                $summary = $record;
            }
        }

        self::assertIsArray($summary);
        self::assertSame(1, $summary['context']['named'], 'Only the vault entry is counted; the subscriptions are never looked at.');
        self::assertSame(1, $summary['context']['applied']);
    }

    /** *The cardholder email enabled.* */
    public function testWithTheSettingOnTheCardholderIsEmailed(): void
    {
        $card = $this->aStoredCard(emailCardholder: true);

        $this->deliverEvent('acu.summary.closedaccount', 'acu-mail-on', [
            'vault_updates' => [$this->entry()],
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $messages = $this->queuedCardholderEmails();
        self::assertCount(1, $messages);
        self::assertSame($card->getId(), $messages[0]->storedCardId);
        self::assertSame(NmiStoredCardInterface::STATUS_CLOSED, $messages[0]->status);
        self::assertSame('en_US', $messages[0]->localeCode, 'A worker has no request, so the locale has to travel with the message.');
    }

    /** *The cardholder email left at its default.* Everything else still happens. */
    public function testWithTheSettingOffNoMailIsSentAndTheCardIsStillMarked(): void
    {
        $card = $this->aStoredCard();

        $this->deliverEvent('acu.summary.closedaccount', 'acu-mail-off', [
            'vault_updates' => [$this->entry()],
        ]);

        self::assertSame([], $this->queuedCardholderEmails(), 'Outbound mail is not something a store inherits from silence.');
        self::assertSame(NmiStoredCardInterface::STATUS_CLOSED, $this->refreshedCard($card)->getStatus(), 'The card is marked either way.');
    }

    /**
     * A renewal earns no mail even with the setting on. The card the shopper saved still works,
     * with a date they never knew — mail about that is mail about nothing.
     */
    public function testARenewalEarnsNoMailEvenWithTheSettingOn(): void
    {
        $this->aStoredCard(expiryMonth: 1, expiryYear: 2020, emailCardholder: true);

        $this->deliverEvent('acu.summary.automaticallyupdated', 'acu-mail-renewal', [
            'vault_updated_expiration_dates' => [$this->entry(expiry: '11/70')],
        ]);

        self::assertSame([], $this->queuedCardholderEmails());
    }

    /**
     * **The email is rendered, in each language the plugin claims to speak.**
     *
     * The tests above prove a message was queued; none of them proves the message becomes an
     * email. A subject and a body that are translation keys render perfectly well as
     * `jpm_martin_sylius_nmi.email...` and nothing fails — so the only way to know is to hand the
     * message to its handler and read what came out.
     *
     * Both statuses in both languages, because there are two subjects and two bodies and a test
     * that renders one of them proves nothing about the other three.
     *
     * @dataProvider theLanguagesThisPluginSpeaks
     */
    public function testTheEmailIsRenderedInTheLanguageTheMessageCarries(string $locale, string $status, string $subjectFragment, string $bodyFragment): void
    {
        $card = $this->aStoredCard(emailCardholder: true);

        $handler = self::getContainer()->get('jpm_martin_sylius_nmi.command_handler.notify_cardholder');
        $handler(new NotifyCardholder((int) $card->getId(), $status, $locale));

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertStringContainsString($subjectFragment, $email->getSubject() ?? '');
        self::assertStringContainsString($bodyFragment, $email->getHtmlBody() ?? $email->getTextBody() ?? '');
        self::assertStringContainsString('1111', $email->getHtmlBody() ?? '', 'The shopper has to know which card.');
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function theLanguagesThisPluginSpeaks(): iterable
    {
        yield 'closed, in english' => ['en_US', NmiStoredCardInterface::STATUS_CLOSED, 'no longer be used', 'Your bank has closed the'];
        yield 'closed, in spanish' => ['es_ES', NmiStoredCardInterface::STATUS_CLOSED, 'ya no se puede usar', 'Tu banco ha dado de baja'];
        yield 'flagged, in english' => ['en_US', NmiStoredCardInterface::STATUS_NEEDS_ATTENTION, 'Please check a saved card', 'It still works for now'];
        yield 'flagged, in spanish' => ['es_ES', NmiStoredCardInterface::STATUS_NEEDS_ATTENTION, 'Revisa una tarjeta guardada', 'De momento sigue funcionando'];
    }

    /**
     * A card the shopper deleted between the event and the worker. Logged and ignored — telling
     * somebody about a card they no longer have is worse than telling them nothing.
     */
    public function testACardDeletedBeforeTheWorkerRanSendsNothing(): void
    {
        $card = $this->aStoredCard(emailCardholder: true);
        $id = (int) $card->getId();

        $this->manager->remove($card);
        $this->manager->flush();

        $handler = self::getContainer()->get('jpm_martin_sylius_nmi.command_handler.notify_cardholder');
        $handler(new NotifyCardholder($id, NmiStoredCardInterface::STATUS_CLOSED, 'en_US'));

        self::assertEmailCount(0);
    }

    /**
     * **Queued, never sent inline.** A mail server having a bad day must not turn a webhook
     * delivery into a failure the gateway retries twenty times over three days.
     *
     * @return list<NotifyCardholder>
     */
    private function queuedCardholderEmails(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.main');

        $found = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof NotifyCardholder) {
                $found[] = $message;
            }
        }

        return $found;
    }

    /** @return array<string, mixed> */
    private function entry(string $number = '400000******0002', string $expiry = '11/70'): array
    {
        return [
            'customer_vault_id' => self::VAULT_ID,
            'billing_id' => '350282046',
            'cc_number' => $number,
            'cc_exp' => $expiry,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
        ];
    }

    private function refreshedCard(NmiStoredCardInterface $card): NmiStoredCardInterface
    {
        $this->manager->clear();

        /** @var NmiStoredCardInterface $fresh */
        $fresh = $this->manager->find(NmiStoredCard::class, $card->getId());

        return $fresh;
    }

    private function aStoredCard(
        string $lastFour = '1111',
        int $expiryMonth = 10,
        int $expiryYear = 2030,
        bool $emailCardholder = false,
    ): NmiStoredCardInterface {
        $payment = $this->aPaymentIn(PaymentInterface::STATE_COMPLETED, $emailCardholder);

        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);

        $customer = new Customer();
        $customer->setEmail(sprintf('ada+%s@example.com', bin2hex(random_bytes(4))));
        $this->manager->persist($customer);

        /** @var NmiStoredCardInterface $card */
        $card = self::getContainer()->get('jpm_martin_sylius_nmi.factory.nmi_stored_card')->createNew();
        $card->setCustomer($customer);
        $card->setPaymentMethod($method);
        $card->setVaultId(self::VAULT_ID);
        $card->setBrand('Visa');
        $card->setLastFour($lastFour);
        $card->setExpiryMonth($expiryMonth);
        $card->setExpiryYear($expiryYear);
        $card->setDefault(true);

        $this->manager->persist($card);
        $this->manager->flush();

        return $card;
    }
}
