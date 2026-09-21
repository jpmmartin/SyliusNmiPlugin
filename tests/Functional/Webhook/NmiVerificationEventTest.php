<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Webhook;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The verification that put a card on file is recorded against its payment, so a notification the
 * gateway sends about it resolves there — rather than being reported as a transaction this store
 * does not know — and changes nothing, because a verification took no money.
 */
final class NmiVerificationEventTest extends WebTestCase
{
    use BuildsAnNmiWebhookDelivery;

    private KernelBrowser $client;

    private EntityManagerInterface $manager;

    private string $code;

    /** A fresh identifier every run, for the reason the transaction event test gives. */
    private string $sale;

    private string $verification;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->code = 'nmi_ver_' . bin2hex(random_bytes(4));
        $this->sale = (string) random_int(10_000_000_000, 99_999_999_999);
        $this->verification = (string) random_int(10_000_000_000, 99_999_999_999);
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

    public function testTheVerificationIsRecordedAgainstItsPaymentAsAZeroAmountValidate(): void
    {
        $payment = $this->aPaymentHoldingAVerification();

        /** @var NmiTransactionRepositoryInterface $transactions */
        $transactions = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_transaction');
        $recorded = $transactions->findOneByAnyTransactionId($this->verification);

        self::assertNotNull($recorded);
        self::assertSame(NmiTransactionInterface::TYPE_VALIDATE, $recorded->getType());
        self::assertSame(0, $recorded->getAmount());
        self::assertSame($payment->getId(), $recorded->getPayment()?->getId());
    }

    public function testANotificationAboutTheVerificationReachesItsPaymentAndChangesNothing(): void
    {
        $payment = $this->aPaymentHoldingAVerification();

        $this->deliver('transaction.validate.success', 'validate-ok', [], $this->verification);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->noticeCount(), 'Reported as a transaction this store does not know.');
        self::assertSame(1, $this->notifyRequestCount($payment));
        self::assertSame('no_action', $this->lastNotifyResult($payment));
        self::assertSame(PaymentInterface::STATE_PROCESSING, $this->stateOf($payment));
    }

    private function aPaymentHoldingAVerification(): PaymentInterface
    {
        // Notices on, so that failing to resolve would be visible as one.
        $payment = $this->aPaymentIn(PaymentInterface::STATE_PROCESSING, notifyUnknownTransactions: true);

        $this->recorder()->record($payment, NmiResponse::fromBody(json_encode([
            'object' => 'transaction',
            'id' => $this->verification,
            'type' => 'cc',
            'amount' => '0.00',
            'currency' => 'USD',
            'auth_code' => '',
            'customer_vault_id' => '1256465022',
            'status' => 'complete',
            'response' => '1',
            'response_text' => 'SUCCESS',
            'response_code' => '100',
        ], \JSON_THROW_ON_ERROR)), NmiTransactionInterface::TYPE_VALIDATE);
        $this->manager->flush();

        return $payment;
    }

    private function noticeCount(): int
    {
        return (int) $this->manager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM jpm_martin_sylius_nmi_gateway_notice WHERE payment_method_code = :code',
            ['code' => $this->code],
        );
    }
}
