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
use JpmMartin\SyliusNmiPlugin\Gateway\Request\StoredCard;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\ThreeDSecureResult;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\VaultCard;
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
            paymentMethodCode: 'nmi_card',
            tokenizationKey: 'tok-public-0123',
            securityKey: self::SECURITY_KEY,
            useAuthorize: false,
            apiBaseUrl: 'https://sandbox.nmi.com/',
        );
    }

    /**
     * The *refused credentials are diagnosable* scenario. A key the host does not accept is the
     * one failure an operator can do nothing about from the shopper's message, so it is written
     * where they will look — and written without the key, which is the one thing a log must
     * never hold.
     *
     * @dataProvider refusedStatuses
     */
    public function testARefusedKeyIsLoggedAgainstTheMethodAndTheHostAndNeverAsItself(int $status): void
    {
        $log = new RecordingLogger();
        $psr17 = new Psr17Factory();
        $client = new NmiClient($this->httpClient, $psr17, $psr17, new NmiAmountFormatter(), $log);
        $this->httpClient->willAnswer($status, '');

        try {
            $client->sale($this->configuration, self::charge());
            self::fail('A refused key must not read as a charge.');
        } catch (NmiGatewayException) {
        }

        self::assertCount(1, $log->records);
        [$level, $message, $context] = $log->records[0];
        self::assertSame('warning', $level);
        $rendered = strtr($message, ['{method}' => (string) $context['method'], '{status}' => (string) $context['status'], '{host}' => (string) $context['host']]);
        self::assertStringContainsString('nmi_card', $rendered);
        self::assertStringContainsString((string) $status, $rendered);
        self::assertStringContainsString('https://sandbox.nmi.com', $rendered);
        self::assertStringContainsString('gateway host', $rendered, 'The line must say what to check.');
        self::assertStringNotContainsString(self::SECURITY_KEY, $rendered . json_encode($context));
    }

    /** @return iterable<string, array{int}> */
    public static function refusedStatuses(): iterable
    {
        yield 'unauthorised' => [401];
        yield 'forbidden' => [403];
    }

    /** A decline or a 4xx that is not about the key writes nothing: those are not the operator's problem. */
    public function testOtherRefusalsAreNotLoggedAsAKeyProblem(): void
    {
        $log = new RecordingLogger();
        $psr17 = new Psr17Factory();
        $client = new NmiClient($this->httpClient, $psr17, $psr17, new NmiAmountFormatter(), $log);
        $this->httpClient->willAnswer(400, '{"error":{"code":"E_INVALID_TRANS_SPECIFIED","message":"Invalid transaction"}}');

        try {
            $client->sale($this->configuration, self::charge());
        } catch (NmiGatewayException) {
        }

        self::assertSame([], $log->records);
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

    /**
     * Asking the gateway to keep the card is a flag on the charge, not a second call — which is
     * what makes a declined payment incapable of leaving a stored card behind.
     */
    public function testAChargeCanAskTheGatewayToKeepTheCard(): void
    {
        $this->answerWith(self::approvedAndVaulted());

        $response = $this->client->sale($this->configuration, new Charge(
            paymentToken: 'tok-once-abc',
            amount: 1234,
            currencyCode: 'usd',
            storeCard: true,
        ));

        // `add_to_vault`. The published example says `add_customer`, which the gateway refuses as
        // an unexpected parameter — established against it rather than read.
        self::assertSame(
            ['add_to_vault' => true],
            $this->httpClient->lastBodyJson()['customer_vault'] ?? null,
        );
        self::assertSame('1730549219', $response->customerVaultId);
        // The transaction that stored the card, kept so a later re-use can cite it.
        self::assertSame(self::TRANSACTION_ID, $response->transactionId);

        // And the three display fields, off the same response.
        self::assertNotNull($response->card);
        self::assertSame('Visa', $response->card->brand);
        self::assertSame('1111', $response->card->lastFour);
        self::assertSame(10, $response->card->expiryMonth);
        self::assertSame(2029, $response->card->expiryYear);
    }

    /** And a charge that was not asked to keep it says nothing about the vault at all. */
    public function testAnOrdinaryChargeMentionsNoVault(): void
    {
        $this->answerWith(self::approved());

        $response = $this->client->sale($this->configuration, new Charge(
            paymentToken: 'tok-once-abc',
            amount: 1234,
            currencyCode: 'usd',
        ));

        self::assertArrayNotHasKey('customer_vault', $this->httpClient->lastBodyJson());
        self::assertNull($response->customerVaultId, 'An empty vault id in the body is not a stored card.');
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

    /**
     * Storing a card is its own resource. The body shape below is the one the gateway accepts:
     * the token nested inside `billing`, beside the address. The published example puts
     * `payment_details`, `cit_mit` and `billing_address` at the top level and is refused.
     */
    public function testStoringACardPostsToItsOwnResource(): void
    {
        $this->answerWith(self::vaultRecord());

        $record = $this->client->createVaultRecord($this->configuration, new VaultCard(
            paymentToken: 'tok-once-abc',
            billing: new BillingDetails(firstName: 'Ada', lastName: 'Lovelace', country: 'GB'),
        ));

        $request = $this->httpClient->lastRequest;
        self::assertNotNull($request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://sandbox.nmi.com/api/v5/customers', (string) $request->getUri());

        self::assertSame([
            'billing' => [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'country' => 'GB',
                'payment_details' => ['payment_token' => 'tok-once-abc'],
            ],
        ], $this->httpClient->lastBodyJson());

        self::assertSame('1929110340', $record->vaultId);
        self::assertSame('349429273', $record->billingId);
        self::assertSame('1111', $record->card?->lastFour);
    }

    /** The answer is a customer, not a transaction — it carries no amount and no authorisation. */
    public function testStoringACardReportsNothingThatCouldReachAStatement(): void
    {
        $this->answerWith(self::vaultRecord());

        $record = $this->client->createVaultRecord($this->configuration, new VaultCard('tok-once-abc'));

        self::assertArrayNotHasKey('amount', $record->raw);
        self::assertArrayNotHasKey('auth_code', $record->raw);
        self::assertArrayNotHasKey('response', $record->raw);
    }

    /**
     * A successful delete answers 204 with an empty body. Read as a transaction that is a
     * malformed response, which is why the transport was split from the meaning.
     */
    public function testForgettingACardAcceptsAnEmptyAnswer(): void
    {
        $this->httpClient->willAnswer(204, '');

        $this->client->deleteVaultRecord($this->configuration, '1929110340');

        $request = $this->httpClient->lastRequest;
        self::assertNotNull($request);
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame('https://sandbox.nmi.com/api/v5/customers/1929110340', (string) $request->getUri());
    }

    /** Deleting one the gateway no longer has is a refusal here; the caller decides it is success. */
    public function testForgettingACardTheGatewayNoLongerHasIsARefusal(): void
    {
        $this->httpClient->willAnswer(404, '{"type":"inputError","error_code":"E_RESOURCE_NOT_FOUND","message":"Customer Vault not found"}');

        $this->expectException(NmiGatewayException::class);

        $this->client->deleteVaultRecord($this->configuration, '1929110340');
    }

    /**
     * Charging a card the gateway already holds.
     *
     * **Every key here was established by submitting it.** The published example charges through
     * `payment_details.customer_vault_id`, and the gateway answers *"Unexpected extra parameters
     * found: 'payment_details.customer_vault_id'"* — so the vault reference belongs at the top
     * level and nothing goes in `payment_details` at all.
     */
    public function testAStoredCardIsChargedThroughTheVaultAndNotThroughPaymentDetails(): void
    {
        $this->answerWith(self::approved());

        $this->client->sale($this->configuration, new Charge(
            paymentToken: null,
            amount: 1299,
            currencyCode: 'USD',
            storedCard: new StoredCard(vaultId: '1730549219', billingId: '349429273', initialTransactionId: '12513506464'),
        ));

        $body = $this->httpClient->lastBodyJson();

        self::assertSame(['id' => '1730549219', 'billing_id' => '349429273'], $body['customer_vault']);
        self::assertArrayNotHasKey('payment_details', $body, 'A stored card is named nowhere else, and the gateway refuses it there.');
        self::assertSame('12.99', $body['amount']);
    }

    /**
     * The stored-credential indicator, whose three values the gateway names in its own refusals:
     * `stored` or `used`, and `customer` or `merchant`. The shopper is at the checkout, so this is
     * a charge they initiated — the gateway's own example says `merchant` because it illustrates a
     * recurring charge, which this is not.
     */
    public function testAReusedCardCitesTheTransactionThatStoredIt(): void
    {
        $this->answerWith(self::approved());

        $this->client->sale($this->configuration, new Charge(
            paymentToken: null,
            amount: 1299,
            currencyCode: 'USD',
            storedCard: new StoredCard(vaultId: '1730549219', initialTransactionId: '12513506464'),
        ));

        self::assertSame([
            'stored_credential_indicator' => 'used',
            'initiated_by' => 'customer',
            'initial_transaction_id' => '12513506464',
        ], $this->httpClient->lastBodyJson()['cit_mit']);
    }

    /**
     * A card added from the account area was never charged, so it has no transaction to cite. The
     * indicator is left out entirely rather than sent empty, and the gateway takes the sale — which
     * is the only reason such a card is chargeable at all.
     */
    public function testACardWithNothingToCiteIsChargedWithoutTheIndicator(): void
    {
        $this->answerWith(self::approved());

        $this->client->sale($this->configuration, new Charge(
            paymentToken: null,
            amount: 1299,
            currencyCode: 'USD',
            storedCard: new StoredCard(vaultId: '1730549219'),
        ));

        $body = $this->httpClient->lastBodyJson();

        self::assertSame(['id' => '1730549219'], $body['customer_vault'], 'A billing record nobody named is not named as null.');
        self::assertArrayNotHasKey('cit_mit', $body);
    }

    /** A charge is paid for by one thing. Naming both, or neither, is a bug rather than a request. */
    public function testAChargeMustNameExactlyOneSourceOfMoney(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Charge(
            paymentToken: 'tok-once-abc',
            amount: 1299,
            currencyCode: 'USD',
            storedCard: new StoredCard(vaultId: '1730549219'),
        );
    }

    public function testAChargeWithNoSourceOfMoneyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Charge(paymentToken: null, amount: 1299, currencyCode: 'USD');
    }

    /**
     * The gateway deduplicates nothing, so asking it to store a card it already stores is not a
     * redundant flag — it is a second vault record with nothing pointing at it.
     */
    public function testAStoredCardCannotBeAskedToBeStoredAgain(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Charge(
            paymentToken: null,
            amount: 1299,
            currencyCode: 'USD',
            storeCard: true,
            storedCard: new StoredCard(vaultId: '1730549219'),
        );
    }

    private static function vaultRecord(): string
    {
        return '{"object":"customer","id":"1929110340","billing":[{"object":"billing","id":"349429273","payment_details":{"card_number":"411111******1111","card_exp":"1025","card_type":"Visa"}}]}';
    }

    private function answerWith(string $body): void
    {
        $this->httpClient->willAnswer(200, $body);
    }

    private static function charge(): Charge
    {
        return new Charge(paymentToken: 'tok-once-abc', amount: 1234, currencyCode: 'USD');
    }

    /**
     * What the gateway returned for a stored card: the vault id sits beside the transaction, and
     * the charge describes the card it took — which is why storing one needs no second call.
     */
    private static function approvedAndVaulted(): string
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(self::approved(), true, 512, \JSON_THROW_ON_ERROR);
        $decoded['customer_vault_id'] = '1730549219';
        $decoded['payment_details'] = [
            'card_number' => '411111******1111',
            'card_exp' => '1029',
            'card_type' => 'Visa',
            'card_bin' => '411111',
        ];

        return json_encode($decoded, \JSON_THROW_ON_ERROR);
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

/** Keeps what was logged, so a test can read the line back. */
final class RecordingLogger extends \Psr\Log\AbstractLogger
{
    /** @var list<array{string, string, array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [(string) $level, (string) $message, $context];
    }
}
