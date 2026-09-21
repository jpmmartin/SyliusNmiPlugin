<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Unit\Gateway;

use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiDeclinedException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiAmountFormatter;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiClient;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfiguration;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\CardVerification;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\ThreeDSecureResult;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Unit\Gateway\Double\FakeNetworkException;
use Tests\JpmMartin\SyliusNmiPlugin\Unit\Gateway\Double\RecordingHttpClient;

/**
 * Putting a card on file without charging it: the request the gateway accepted, and how each of its
 * answers is read.
 *
 * The request is the one established against the sandbox on 2026-09-21 and recorded in the change's
 * design. The approval and the refusal below are abridged from the sandbox's own replies. The answers
 * are read exactly as a sale's are — an approval is a transaction, a decline is the issuer's, a
 * refusal is the gateway's, and silence is not a refusal.
 */
final class NmiCardVerificationTest extends TestCase
{
    /** The sandbox's reply to the verification, abridged. Note the zero amount and empty authorisation code. */
    private const APPROVED = '{"object":"transaction","id":"12584742193","type":"cc","amount":"0.00","currency":"USD","auth_code":"","customer_vault_id":"1256465022","status":"complete","response":"1","response_text":"SUCCESS","response_code":"100","payment_details":{"card_number":"411111******1111","card_exp":"1031","card_type":"Visa","card_bin":"411111"},"actions":[{"id":"4344235085","type":"validate","amount":"0.00","success":true,"response":"1","response_text":"SUCCESS","response_code":"100"}]}';

    /** The sandbox's refusal of a value it does not take, verbatim. */
    private const REFUSED = '{"type":"validationError","errorCode":"E_INVALID_SUBMISSION","message":"The provided data is invalid.","refId":"218501885","details":[{"fieldName":"cit_mit.initiated_by","message":"Invalid value. Acceptable values are: \'customer\' or \'merchant\'"}]}';

    private RecordingHttpClient $httpClient;

    private NmiClient $client;

    private NmiGatewayConfiguration $configuration;

    protected function setUp(): void
    {
        $psr17 = new Psr17Factory();
        $this->httpClient = new RecordingHttpClient($psr17);
        $this->client = new NmiClient($this->httpClient, $psr17, $psr17, new NmiAmountFormatter());
        $this->configuration = new NmiGatewayConfiguration(
            paymentMethodCode: 'nmi_card',
            tokenizationKey: 'tok-public-0123',
            securityKey: 'sec-private-key-4567',
            useAuthorize: false,
            apiBaseUrl: 'https://sandbox.nmi.com/',
        );
    }

    public function testItPostsTheBodyTheSandboxAcceptedToTheValidateResource(): void
    {
        $this->httpClient->willAnswer(200, self::APPROVED);

        $this->client->verifyAndStore($this->configuration, new CardVerification(
            paymentToken: '00000000-000000-000000-000000000000',
            currencyCode: 'usd',
            orderId: '000000021',
            threeDSecure: new ThreeDSecureResult(status: 'verified', cavv: 'Y2FyZGluYWxjb21tZXJjZWF1dGg=', eci: '05', threeDsVersion: '2.2.0'),
        ));

        $request = $this->httpClient->lastRequest;
        self::assertNotNull($request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://sandbox.nmi.com/api/v5/payments/validate', (string) $request->getUri());
        self::assertSame([
            'currency' => 'USD',
            'payment_details' => ['payment_token' => '00000000-000000-000000-000000000000'],
            'customer_vault' => ['add_to_vault' => true],
            'cit_mit' => ['stored_credential_indicator' => 'stored', 'initiated_by' => 'customer'],
            'cardholder_auth' => ['status' => 'verified', 'cavv' => 'Y2FyZGluYWxjb21tZXJjZWF1dGg=', 'eci' => '05', 'three_ds_version' => '2.2.0'],
            'order_details' => ['id' => '000000021'],
        ], $this->httpClient->lastBodyJson());
    }

    /** No amount, ever: this is the shape of a request that cannot take money. */
    public function testTheRequestNamesNoAmount(): void
    {
        $this->httpClient->willAnswer(200, self::APPROVED);

        $this->client->verifyAndStore($this->configuration, new CardVerification('00000000-000000-000000-000000000000', 'USD'));

        self::assertArrayNotHasKey('amount', $this->httpClient->lastBodyJson());
    }

    /** Without an authentication result or an order, those keys are absent rather than empty — the gateway refuses fields it does not expect. */
    public function testEmptyOptionalPartsAreLeftOut(): void
    {
        $this->httpClient->willAnswer(200, self::APPROVED);

        $this->client->verifyAndStore($this->configuration, new CardVerification('00000000-000000-000000-000000000000', 'USD'));

        $body = $this->httpClient->lastBodyJson();
        self::assertArrayNotHasKey('cardholder_auth', $body);
        self::assertArrayNotHasKey('order_details', $body);
    }

    public function testAnApprovalNamesTheInitialTransactionTheVaultRecordAndTheCard(): void
    {
        $this->httpClient->willAnswer(200, self::APPROVED);

        $response = $this->client->verifyAndStore($this->configuration, new CardVerification('00000000-000000-000000-000000000000', 'USD'));

        self::assertSame('12584742193', $response->transactionId);
        self::assertSame('1256465022', $response->customerVaultId);
        self::assertSame('Visa', $response->card?->brand);
        self::assertSame('1111', $response->card?->lastFour);
        self::assertSame(10, $response->card?->expiryMonth);
        self::assertSame(2031, $response->card?->expiryYear);
        self::assertSame('0.00', $response->amount);
    }

    public function testADeclineIsTheIssuers(): void
    {
        $declined = str_replace(['"response":"1"', '"response_text":"SUCCESS"', '"response_code":"100"'], ['"response":"2"', '"response_text":"DECLINE"', '"response_code":"200"'], self::APPROVED);
        $this->httpClient->willAnswer(200, $declined);

        $this->expectException(NmiDeclinedException::class);

        $this->client->verifyAndStore($this->configuration, new CardVerification('00000000-000000-000000-000000000000', 'USD'));
    }

    public function testARefusalIsTheGatewaysAndCarriesItsWording(): void
    {
        $this->httpClient->willAnswer(422, self::REFUSED);

        try {
            $this->client->verifyAndStore($this->configuration, new CardVerification('00000000-000000-000000-000000000000', 'USD'));
            self::fail('A refused request must not read as a card put on file.');
        } catch (NmiGatewayException $exception) {
            self::assertStringContainsString('cit_mit.initiated_by', (string) $exception->getGatewayMessage());
        }
    }

    public function testNoAnswerIsNotARefusal(): void
    {
        $this->httpClient->willThrow(new FakeNetworkException('Connection timed out'));

        $this->expectException(NmiTransportException::class);

        $this->client->verifyAndStore($this->configuration, new CardVerification('00000000-000000-000000-000000000000', 'USD'));
    }

    public function testAnAnswerAboutTheGatewaysOwnConditionIsNotARefusalEither(): void
    {
        $this->httpClient->willAnswer(503, '');

        $this->expectException(NmiTransportException::class);

        $this->client->verifyAndStore($this->configuration, new CardVerification('00000000-000000-000000-000000000000', 'USD'));
    }
}
