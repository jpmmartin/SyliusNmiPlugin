<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Webhook;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiGatewayNoticeInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Double\RecordingLogger;

/**
 * Money taken back by cardholders' issuers.
 *
 * **Not observable against the sandbox and never will be**: a chargeback is raised by a card
 * network weeks after a payment, so no amount of test-mode work produces one. The payloads here
 * are the gateway's published sample, and what is asserted is everything a store can control —
 * that each entry is resolved on its own, that an unrecognised one costs the others nothing, and
 * that the operator can see the result.
 *
 * **The resolution rests on one stated assumption.** An entry's only candidate key is `id`, and
 * the documentation never says whether it identifies the chargeback or the transaction. It is
 * resolved as a transaction, and a miss leaves the chargeback recorded but unattached — so if the
 * assumption is wrong the failure is visible and harmless rather than silent and wrong.
 */
final class NmiChargebackEventTest extends WebTestCase
{
    use BuildsAnNmiWebhookDelivery;

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

        $this->code = 'nmi_cbk_' . bin2hex(random_bytes(4));
        $this->sale = (string) random_int(10_000_000_000, 99_999_999_999);

        $this->logger = new RecordingLogger();
        self::getContainer()->set('logger', $this->logger);
    }

    protected function tearDown(): void
    {
        foreach (['jpm_martin_sylius_nmi_gateway_notice', 'jpm_martin_sylius_nmi_received_event'] as $table) {
            $this->manager->getConnection()->executeStatement(
                sprintf('DELETE FROM %s WHERE payment_method_code = :code', $table),
                ['code' => $this->code],
            );
        }

        parent::tearDown();
    }

    /**
     * *A batch of chargebacks.* Three, one of which names nothing this store knows: the two it
     * does know are attached to their orders, the third is recorded unattached, and the batch does
     * not fail.
     */
    public function testABatchOfThreeWithOneUnknownRecordsAllThreeAndAttachesTwo(): void
    {
        $payment = $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);
        $second = $this->aSecondTransactionOn($payment);

        $this->deliverEvent('chargeback.batch.complete', 'cbk-batch', [
            'count' => 3,
            'chargeback_amount' => '35.31',
            'chargebacks' => [
                $this->chargeback($this->sale, '11.11', '101: Introductory chargeback'),
                $this->chargeback('9999999999', '18.11', '102: Someone else\'s order'),
                $this->chargeback($second, '6.09', '103: Late presentment'),
            ],
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'One unresolvable entry must not fail the batch.');

        $notices = $this->notices();
        self::assertCount(3, $notices, 'Every chargeback is recorded, including the one nobody here recognises.');

        $attached = array_filter($notices, static fn (NmiGatewayNoticeInterface $n): bool => null !== $n->getPayment());
        self::assertCount(2, $attached);

        $stranger = $this->notice('9999999999');
        self::assertNull($stranger->getPayment(), 'Nothing is ever attached to an order it does not belong to.');
    }

    /**
     * The amount becomes a number only when a resolved order supplies the currency. Converting a
     * decimal without one guesses at how many places it has — right for dollars, wrong for yen.
     */
    public function testAResolvedChargebackCarriesItsAmountAndAnUnresolvedOneKeepsItInWords(): void
    {
        $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);

        $this->deliverEvent('chargeback.batch.complete', 'cbk-amounts', [
            'chargebacks' => [
                $this->chargeback($this->sale, '11.11', '101: Introductory chargeback'),
                $this->chargeback('9999999999', '18.11', '102: Not ours'),
            ],
        ]);

        $ours = $this->notice($this->sale);
        self::assertSame(1111, $ours->getAmount());
        self::assertSame('USD', $ours->getCurrencyCode());
        self::assertSame('101: Introductory chargeback', $ours->getReason());

        $stranger = $this->notice('9999999999');
        self::assertNull($stranger->getAmount());
        self::assertNull($stranger->getCurrencyCode());
        self::assertStringContainsString('18.11', (string) $stranger->getReason(), 'The figure is kept where it cannot be wrong.');
    }

    /** The same batch delivered twice leaves one notice per chargeback. */
    public function testARedeliveredBatchDoesNotDoubleTheNotices(): void
    {
        $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);

        $body = ['chargebacks' => [$this->chargeback($this->sale, '11.11', '101: Introductory chargeback')]];

        $this->deliverEvent('chargeback.batch.complete', 'cbk-dup-a', $body);
        $this->deliverEvent('chargeback.batch.complete', 'cbk-dup-b', $body);

        self::assertCount(1, $this->notices());
    }

    /**
     * *Surfaced to the operator, always.* Visible on the page an operator opens, and — the other
     * half of the requirement — **no setting was added that could hide it**.
     */
    public function testAChargebackIsVisibleToTheOperatorAndHasNoSettingToHideIt(): void
    {
        $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);
        $this->deliverEvent('chargeback.batch.complete', 'cbk-visible', [
            'chargebacks' => [$this->chargeback($this->sale, '11.11', '101: Introductory chargeback')],
        ]);

        $admin = self::getContainer()->get('sylius.factory.admin_user')->createNew();
        $admin->setEmail(sprintf('ada+%s@example.com', bin2hex(random_bytes(4))));
        $admin->setUsername('ada-' . bin2hex(random_bytes(4)));
        $admin->setPlainPassword('not-checked');
        $admin->setEnabled(true);
        $admin->setLocaleCode('en_US');
        $this->manager->persist($admin);
        $this->manager->flush();

        $this->client->loginUser($admin, 'admin');
        $this->client->request('GET', '/admin/nmi-notices');

        $page = (string) $this->client->getResponse()->getContent();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString($this->sale, $page);
        self::assertStringContainsString('Chargeback', $page);
        self::assertStringContainsString('101: Introductory chargeback', $page);

        // The second half of the requirement, and the easier one to let slip: every configuration
        // key this gateway has, read off the class rather than off a list somebody remembered to
        // update. None of them may be about chargebacks.
        foreach ((new \ReflectionClass(NmiGatewayFactory::class))->getConstants() as $name => $value) {
            if (!str_starts_with($name, 'CONFIG_')) {
                continue;
            }

            self::assertStringNotContainsString('chargeback', (string) $value, 'A chargeback has no setting to suppress it.');
        }
    }

    /** @return array<string, mixed> */
    private function chargeback(string $id, string $amount, string $reason): array
    {
        return [
            'id' => $id,
            'date' => '3/29/2020',
            'customer_name' => 'Someone Smith',
            'cc_number' => '411111******1111',
            'amount' => $amount,
            'reason' => $reason,
        ];
    }

    /** A second sale on the same payment, so a batch can name two things this store knows. */
    private function aSecondTransactionOn(PaymentInterface $payment): string
    {
        $transactionId = (string) random_int(10_000_000_000, 99_999_999_999);

        $this->recorder()->record(
            $payment,
            \JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse::fromBody(json_encode([
                'object' => 'transaction',
                'id' => $transactionId,
                'type' => 'cc',
                'amount' => '6.09',
                'currency' => 'USD',
                'status' => 'pendingsettlement',
                'response' => '1',
                'response_text' => 'SUCCESS',
                'response_code' => '100',
            ], \JSON_THROW_ON_ERROR)),
            \JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface::TYPE_CAPTURE,
        );
        $this->manager->flush();

        return $transactionId;
    }

    /** @return list<NmiGatewayNoticeInterface> */
    private function notices(): array
    {
        $this->manager->clear();

        /** @var list<NmiGatewayNoticeInterface> $notices */
        $notices = self::getContainer()
            ->get('jpm_martin_sylius_nmi.repository.nmi_gateway_notice')
            ->findBy(['paymentMethodCode' => $this->code])
        ;

        return $notices;
    }

    private function notice(string $reference): NmiGatewayNoticeInterface
    {
        foreach ($this->notices() as $notice) {
            if ($reference === $notice->getReference()) {
                return $notice;
            }
        }

        self::fail(sprintf('No chargeback notice was recorded for "%s".', $reference));
    }
}
