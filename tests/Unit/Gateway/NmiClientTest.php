<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Unit\Gateway;

use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiDeclinedException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiAmountFormatter;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiClient;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfiguration;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\BillingDetails;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\Charge;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\ThreeDSecureResult;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Unit\Gateway\Double\FakeNetworkException;
use Tests\JpmMartin\SyliusNmiPlugin\Unit\Gateway\Double\RecordingHttpClient;

final class NmiClientTest extends TestCase
{
    private const SECURITY_KEY = 'sec-private-key-4567';

    private const TRANSACTION_ID = '12513506464';

    private RecordingHttpClient $httpClient;

    private NmiClient $client;

    private NmiGatewayConfiguration $configuration;

    protected function setUp(): void
    {
        $psr17 = new Psr17Factory();
        $this->httpClient = new RecordingHttpClient($psr17);
        $this->client = new NmiClient($this->httpClient, $psr17, $psr17, new NmiAmountFormatter());
        $this->configuration = new NmiGatewayConfiguration(
            tokenizationKey: 'tok-public-0123',
            securityKey: self::SECURITY_KEY,
            environment: 'sandbox',
            useAuthorize: false,
            apiBaseUrl: 'https://sandbox.nmi.com/',
        );
    }

    public function testAnApprovedSaleIsPostedAsJsonAndParsed(): void
    {
        $this->answerWith(self::approved());

        $response = $this->client->sale($this->configuration, new Charge(
            paymentToken: 'tok-once-abc',
            amount: 1234,
            currencyCode: 'usd',
            orderId: '000000042',
            orderDescription: 'Order #000000042',
            ipAddress: '203.0.113.9',
        ));

        self::assertTrue($response->isApproved());
        self::assertSame(100, $response->responseCode);
        self::assertSame(self::TRANSACTION_ID, $response->transactionId);

        $request = $this->httpClient->lastRequest;
        self::assertNotNull($request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://sandbox.nmi.com/api/v5/payments/sale', (string) $request->getUri());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));

        self::assertSame([
            'amount' => '12.34',
            'currency' => 'USD',
            'payment_details' => ['payment_token' => 'tok-once-abc'],
            'order_details' => [
                'id' => '000000042',
                'order_description' => 'Order #000000042',
                'ip_address' => '203.0.113.9',
            ],
        ], $this->httpClient->lastBodyJson());
    }

    /** The key is the whole header value. A scheme prefix is read as no key at all. */
    public function testTheKeyIsSentBareInTheAuthorizationHeader(): void
    {
        $this->answerWith(self::approved());

        $this->client->sale($this->configuration, self::charge());

        self::assertSame(self::SECURITY_KEY, $this->httpClient->lastRequest?->getHeaderLine('Authorization'));
    }

    /** A body the gateway does not expect is rejected outright, so absent values are omitted. */
    public function testOptionalGroupsAreOmittedRatherThanSentEmpty(): void
    {
        $this->answerWith(self::approved());

        $this->client->sale($this->configuration, self::charge());

        $body = $this->httpClient->lastBodyJson();
        self::assertArrayNotHasKey('order_details', $body);
        self::assertArrayNotHasKey('billing_address', $body);
        self::assertArrayNotHasKey('cardholder_auth', $body);
    }

    public function testBillingDetailsAndAuthenticationTravelAsNestedObjects(): void
    {
        $this->answerWith(self::approved());

        $this->client->sale($this->configuration, new Charge(
            paymentToken: 'tok-once-abc',
            amount: 500,
            currencyCode: 'USD',
            billing: new BillingDetails(firstName: 'Ada', lastName: 'Lovelace', city: 'London', country: 'GB'),
            threeDSecure: new ThreeDSecureResult(
                status: 'verified',
                cavv: 'Y2FyZGluYWxjb21tZXJjZWF1dGg=',
                eci: '05',
                threeDsVersion: '2.2.0',
                directoryServerId: '3f6fb1f8-f719-46c9-905b-bab446f4de30',
            ),
        ));

        $body = $this->httpClient->lastBodyJson();
        self::assertSame(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'city' => 'London', 'country' => 'GB'], $body['billing_address']);
        self::assertSame([
            'status' => 'verified',
            'cavv' => 'Y2FyZGluYWxjb21tZXJjZWF1dGg=',
            'eci' => '05',
            'three_ds_version' => '2.2.0',
            'directory_server_id' => '3f6fb1f8-f719-46c9-905b-bab446f4de30',
        ], $body['cardholder_auth']);
    }

    public function testAnAuthorisationGoesToItsOwnPath(): void
    {
        $this->answerWith(self::approved());

        $this->client->authorize($this->configuration, self::charge());

        self::assertSame('https://sandbox.nmi.com/api/v5/payments/auth', (string) $this->httpClient->lastRequest?->getUri());
    }

    /** @param array<string, mixed> $expectedBody */
    #[DataProvider('followOnOperations')]
    public function testAFollowOnOperationAddressesTheTransactionInThePath(string $expectedPath, array $expectedBody, \Closure $call): void
    {
        $this->answerWith(self::approved());

        $call($this->client, $this->configuration);

        self::assertSame('https://sandbox.nmi.com/api/v5/payments/' . $expectedPath, (string) $this->httpClient->lastRequest?->getUri());
        self::assertSame($expectedBody, $this->httpClient->lastBodyJson());
    }

    /** @return iterable<string, array{string, array<string, mixed>, \Closure}> */
    public static function followOnOperations(): iterable
    {
        yield 'capture' => [
            self::TRANSACTION_ID . '/capture',
            ['amount' => '12.34'],
            static fn (NmiClient $c, NmiGatewayConfiguration $g) => $c->capture($g, self::TRANSACTION_ID, 1234, 'USD'),
        ];

        yield 'partial refund' => [
            self::TRANSACTION_ID . '/refund',
            ['amount' => '5.00'],
            static fn (NmiClient $c, NmiGatewayConfiguration $g) => $c->refund($g, self::TRANSACTION_ID, 500, 'USD'),
        ];

        yield 'full refund' => [
            self::TRANSACTION_ID . '/refund',
            ['amount' => '0.00'],
            static fn (NmiClient $c, NmiGatewayConfiguration $g) => $c->refund($g, self::TRANSACTION_ID, null, 'USD'),
        ];
    }

    /** An empty JSON array is not an empty object, and the gateway rejects the array. */
    public function testAVoidSendsAnEmptyObject(): void
    {
        $this->answerWith(self::approved());

        $this->client->void($this->configuration, self::TRANSACTION_ID);

        self::assertSame('https://sandbox.nmi.com/api/v5/payments/' . self::TRANSACTION_ID . '/void', (string) $this->httpClient->lastRequest?->getUri());
        self::assertSame('{}', $this->httpClient->lastBodyRaw());
    }

    public function testRetrievingATransactionSendsNoBody(): void
    {
        $this->answerWith(self::approved());

        $response = $this->client->retrieve($this->configuration, self::TRANSACTION_ID);

        self::assertSame('GET', $this->httpClient->lastRequest?->getMethod());
        self::assertSame('https://sandbox.nmi.com/api/v5/payments/' . self::TRANSACTION_ID, (string) $this->httpClient->lastRequest?->getUri());
        self::assertSame('', $this->httpClient->lastBodyRaw());
        self::assertTrue($response->isApproved());
    }

    /** A decline arrives with a 200: only the body says the issuer said no. */
    #[DataProvider('declineCodes')]
    public function testADeclineIsTheShoppersProblem(int $code, string $text): void
    {
        $this->answerWith(self::transaction(response: '2', code: (string) $code, text: $text, status: 'failed'));

        try {
            $this->client->sale($this->configuration, self::charge());
            self::fail('A decline must not be reported as an approval.');
        } catch (NmiDeclinedException $exception) {
            self::assertSame($text, $exception->getDeclineReason());
            self::assertSame($code, $exception->getResponse()->responseCode);
        }
    }

    /** @return iterable<string, array{int, string}> */
    public static function declineCodes(): iterable
    {
        yield 'declined by the processor' => [200, 'DECLINE'];
        yield 'insufficient funds' => [202, 'Insufficient funds'];
        yield 'expired card' => [223, 'Expired card'];
        yield 'invalid security code' => [225, 'Invalid card security code'];
        yield 'retry in a few days' => [264, 'Declined-Retry in a few days'];
    }

    /** A `3` still exists in the body even though most rejections are now an HTTP status. */
    public function testAnInBodyErrorIsTheMerchantsProblem(): void
    {
        $this->answerWith(self::transaction(response: '3', code: '300', text: 'Transaction was rejected by gateway'));

        $this->expectException(NmiGatewayException::class);

        $this->client->sale($this->configuration, self::charge());
    }

    #[DataProvider('refusals')]
    public function testARefusalCarriesTheGatewaysOwnWording(int $status, string $body, string $expectedMessage): void
    {
        $this->httpClient->willAnswer($status, $body);

        try {
            $this->client->sale($this->configuration, self::charge());
            self::fail('A refusal must not be reported as an approval.');
        } catch (NmiGatewayException $exception) {
            self::assertSame($status, $exception->getHttpStatus());
            self::assertSame($expectedMessage, $exception->getGatewayMessage());
        }
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function refusals(): iterable
    {
        yield 'an invalid card number' => [
            400,
            '{"type":"inputError","error_code":"E_INVALID_CC_NUMBER","message":"Invalid Credit Card Number","ref_id":"186207101"}',
            'Invalid Credit Card Number',
        ];

        yield 'an operation the transaction is past' => [
            400,
            '{"type":"inputError","error_code":"E_INVALID_TRANS_SPECIFIED","message":"A capture requires that the existing transaction be an AUTH"}',
            'A capture requires that the existing transaction be an AUTH',
        ];

        yield 'more than was authorised' => [
            400,
            '{"type":"inputError","error_code":"E_AUTH_AMOUNT_EXCEEDED","message":"The specified amount of 99.00 exceeds the authorization amount of 4.00"}',
            'The specified amount of 99.00 exceeds the authorization amount of 4.00',
        ];

        yield 'a bad key' => [
            401,
            '{"type":"authenticationError","error_code":"E_AUTHENTICATION_MISSING","message":"Missing/Invalid Authentication"}',
            'Missing/Invalid Authentication',
        ];

        yield 'an unknown transaction' => [
            404,
            '{"type":"inputError","error_code":"E_RESOURCE_NOT_FOUND","message":"Transaction not found"}',
            'Transaction not found',
        ];

        yield 'a body the gateway would not accept' => [
            422,
            '{"type":"validationError","message":"The provided data is invalid.","details":[{"fieldName":"amount","message":"The value cannot be empty"}]}',
            'The provided data is invalid. (amount: The value cannot be empty)',
        ];
    }

    /**
     * These say something about the gateway, not about the transaction, so the outcome is
     * unknown — and an unknown outcome must never look like a refusal, which would let a
     * caller conclude that nothing was charged.
     */
    #[DataProvider('inconclusiveStatuses')]
    public function testAStatusThatSaysNothingAboutTheTransactionIsATransportFailure(int $status): void
    {
        $this->httpClient->willAnswer($status, '');

        $this->expectException(NmiTransportException::class);

        $this->client->sale($this->configuration, self::charge());
    }

    /** @return iterable<string, array{int}> */
    public static function inconclusiveStatuses(): iterable
    {
        yield 'rate limited' => [429];
        yield 'gateway error' => [500];
        yield 'bad gateway' => [502];
        yield 'gateway unavailable' => [503];
    }

    public function testAnUnreachableGatewayIsNeverAnApproval(): void
    {
        $this->httpClient->willThrow(new FakeNetworkException('Connection timed out'));

        $this->expectException(NmiTransportException::class);

        $this->client->sale($this->configuration, self::charge());
    }

    /** Nothing from the request may travel out in an exception message. */
    public function testATransportFailureMessageCarriesNothingFromTheRequest(): void
    {
        $this->httpClient->willThrow(new FakeNetworkException('POST https://sandbox.nmi.com failed'));

        try {
            $this->client->sale($this->configuration, self::charge());
            self::fail('Expected a transport failure.');
        } catch (NmiTransportException $exception) {
            self::assertStringNotContainsString(self::SECURITY_KEY, $exception->getMessage());
            self::assertStringNotContainsString('tok-once-abc', $exception->getMessage());
        }
    }

    public function testAnAnswerThatIsNotATransactionIsAGatewayError(): void
    {
        $this->httpClient->willAnswer(200, '<html><body>Service temporarily unavailable</body></html>');

        $this->expectException(NmiGatewayException::class);

        $this->client->sale($this->configuration, self::charge());
    }

    private function answerWith(string $body): void
    {
        $this->httpClient->willAnswer(200, $body);
    }

    private static function charge(): Charge
    {
        return new Charge(paymentToken: 'tok-once-abc', amount: 1234, currencyCode: 'USD');
    }

    private static function approved(): string
    {
        return self::transaction();
    }

    private static function transaction(
        string $response = '1',
        string $code = '100',
        string $text = 'SUCCESS',
        string $status = 'pendingsettlement',
    ): string {
        return json_encode([
            'object' => 'transaction',
            'id' => self::TRANSACTION_ID,
            'type' => 'cc',
            'amount' => '12.34',
            'currency' => 'USD',
            'auth_code' => '123456',
            'avs_response' => '',
            'cvv_response' => 'M',
            'status' => $status,
            'response' => $response,
            'response_text' => $text,
            'response_code' => $code,
        ], \JSON_THROW_ON_ERROR);
    }
}
