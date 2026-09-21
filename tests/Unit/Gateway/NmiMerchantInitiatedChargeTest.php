<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Unit\Gateway;

use JpmMartin\SyliusNmiPlugin\Entity\NmiCardOnFile;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiAmountFormatter;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiClient;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfiguration;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\CardOnFileChargeFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Tests\JpmMartin\SyliusNmiPlugin\Unit\Gateway\Double\RecordingHttpClient;

/**
 * What the gateway is told when the store charges a card on file with nobody present.
 *
 * The request is the one the sandbox accepted and approved on 2026-09-21. Built by the factory and
 * sent by the real client, so what is asserted is the body that actually leaves the store.
 */
final class NmiMerchantInitiatedChargeTest extends TestCase
{
    private const APPROVED = '{"object":"transaction","id":"12584746059","type":"cc","amount":"109.51","currency":"USD","auth_code":"123456","customer_vault_id":"1256465022","status":"pendingsettlement","response":"1","response_text":"SUCCESS","response_code":"100"}';

    private RecordingHttpClient $httpClient;

    private NmiClient $client;

    protected function setUp(): void
    {
        $psr17 = new Psr17Factory();
        $this->httpClient = new RecordingHttpClient($psr17);
        $this->client = new NmiClient($this->httpClient, $psr17, $psr17, new NmiAmountFormatter());
        $this->httpClient->willAnswer(200, self::APPROVED);
    }

    public function testItIsDeclaredMerchantInitiatedAndCitesTheVerification(): void
    {
        $this->charge();

        self::assertSame([
            'stored_credential_indicator' => 'used',
            'initiated_by' => 'merchant',
            'initial_transaction_id' => '12584742193',
        ], $this->httpClient->lastBodyJson()['cit_mit'] ?? null);
    }

    public function testItNamesTheVaultRecordAndNothingInPaymentDetails(): void
    {
        $this->charge();

        $body = $this->httpClient->lastBodyJson();
        self::assertSame(['id' => '1256465022'], $body['customer_vault'] ?? null);
        self::assertArrayNotHasKey('payment_details', $body);
    }

    public function testItChargesThePaymentsOwnAmountAndOrder(): void
    {
        $this->charge();

        $body = $this->httpClient->lastBodyJson();
        self::assertSame('109.51', $body['amount']);
        self::assertSame('USD', $body['currency']);
        self::assertSame(['id' => '000000021'], $body['order_details'] ?? null);
    }

    /** Nobody is present: no authentication result, and no address a browser came from. */
    public function testItCarriesNoAuthenticationAndNoIpAddress(): void
    {
        $this->charge();

        $body = $this->httpClient->lastBodyJson();
        self::assertArrayNotHasKey('cardholder_auth', $body);
        self::assertArrayNotHasKey('ip_address', (array) ($body['order_details'] ?? []));
    }

    /** A store's own fields travel; a store's own declaration does not replace the plugin's. */
    public function testStoreFieldsTravelButCannotChangeTheDeclaration(): void
    {
        $this->charge(['descriptor' => 'SHOP*ORDER', 'cit_mit' => ['initiated_by' => 'customer', 'stored_credential_indicator' => 'stored']]);

        $body = $this->httpClient->lastBodyJson();
        self::assertSame('SHOP*ORDER', $body['descriptor'] ?? null);
        self::assertSame('merchant', $body['cit_mit']['initiated_by'] ?? null);
        self::assertSame('used', $body['cit_mit']['stored_credential_indicator'] ?? null);
    }

    /** @param array<string, mixed> $extra */
    private function charge(array $extra = []): void
    {
        $order = new Order();
        $order->setNumber('000000021');

        $payment = new Payment();
        $payment->setOrder($order);
        $payment->setAmount(10951);
        $payment->setCurrencyCode('USD');

        $card = new NmiCardOnFile();
        $card->setVaultId('1256465022');
        $card->setInitialTransactionId('12584742193');

        $this->client->sale(
            new NmiGatewayConfiguration(
                paymentMethodCode: 'nmi_card',
                tokenizationKey: 'tok-public-0123',
                securityKey: 'sec-private-key-4567',
                useAuthorize: false,
                apiBaseUrl: 'https://sandbox.nmi.com/',
            ),
            (new CardOnFileChargeFactory())->forCardOnFile($payment, $card, $extra),
        );
    }
}
