<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Unit\Gateway;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiAmountFormatter;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiClient;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfiguration;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\Charge;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\StoredCard;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\ThreeDSecureResult;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Unit\Gateway\Double\RecordingHttpClient;

/**
 * The charges a shopper makes at the checkout, byte for byte as they were before the plugin learned
 * to charge a card without the shopper.
 *
 * The fixtures were captured from the client *before* it was changed, and are compared as raw
 * strings rather than as decoded arrays: a reordered key or a changed number format is a different
 * request, and the only promise worth making about the attended path is that it did not move at
 * all. The second fixture keeps a pre-existing behaviour deliberately — for a card with no initial
 * transaction the plugin sends no `cit_mit` of its own, so a store's own reaches the gateway. That
 * is recorded here, not endorsed, so that changing it is a decision rather than an accident.
 */
final class NmiAttendedChargeBodyTest extends TestCase
{
    private const APPROVED = '{"object":"transaction","id":"12513506464","type":"cc","amount":"12.99","currency":"USD","auth_code":"123456","status":"pendingsettlement","response":"1","response_text":"SUCCESS","response_code":"100"}';

    #[DataProvider('attendedCharges')]
    public function testTheAttendedChargeIsTheOneSentBeforeThisChange(string $fixture, Charge $charge): void
    {
        $psr17 = new Psr17Factory();
        $http = new RecordingHttpClient($psr17);
        $client = new NmiClient($http, $psr17, $psr17, new NmiAmountFormatter());
        $http->willAnswer(200, self::APPROVED);

        $client->sale(new NmiGatewayConfiguration(
            paymentMethodCode: 'nmi_card',
            tokenizationKey: 'tok-public-0123',
            securityKey: 'sec-private-key-4567',
            useAuthorize: false,
            apiBaseUrl: 'https://sandbox.nmi.com/',
        ), $charge);

        self::assertNotNull($http->lastRequest);
        self::assertSame(
            (string) file_get_contents(__DIR__ . '/Fixture/attended/' . $fixture . '.json'),
            (string) $http->lastRequest->getBody(),
        );
    }

    /** @return iterable<string, array{string, Charge}> */
    public static function attendedCharges(): iterable
    {
        $authentication = new ThreeDSecureResult(
            status: 'verified',
            cavv: 'Y2FyZGluYWxjb21tZXJjZWF1dGg=',
            xid: 'eGlkLXZhbHVl',
            eci: '05',
            threeDsVersion: '2.2.0',
            directoryServerId: 'c0ffee00-0000-4000-8000-000000000000',
        );

        yield 'a saved card that cites its initial transaction' => ['stored-card-with-initial-transaction', new Charge(
            paymentToken: null,
            amount: 1299,
            currencyCode: 'usd',
            orderId: '000000021',
            ipAddress: '203.0.113.7',
            threeDSecure: $authentication,
            storedCard: new StoredCard(vaultId: '1730549219', billingId: '349429273', initialTransactionId: '12513506464'),
        )];

        yield 'a card from the account area, with a store field of its own' => ['stored-card-from-account-area-with-store-extra', new Charge(
            paymentToken: null,
            amount: 1299,
            currencyCode: 'USD',
            orderId: '000000022',
            storedCard: new StoredCard(vaultId: '1929110340'),
            extra: ['descriptor' => 'SHOP*ORDER', 'cit_mit' => ['initiated_by' => 'merchant']],
        )];

        yield 'a card saved while paying' => ['token-saved-while-paying', new Charge(
            paymentToken: '00000000-000000-000000-000000000000',
            amount: 1299,
            currencyCode: 'USD',
            orderId: '000000023',
            ipAddress: '203.0.113.7',
            threeDSecure: $authentication,
            storeCard: true,
        )];

        yield 'a card typed at the checkout' => ['token', new Charge(
            paymentToken: '00000000-000000-000000-000000000000',
            amount: 1299,
            currencyCode: 'USD',
            orderId: '000000024',
        )];
    }
}
