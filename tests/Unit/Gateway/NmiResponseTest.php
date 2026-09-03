<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Unit\Gateway;

use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The bodies below were taken verbatim from the gateway, only trimmed. Inventing them would
 * test this parser against an idea of the API rather than the API.
 */
final class NmiResponseTest extends TestCase
{
    private const APPROVED_SALE = <<<'JSON'
        {"object":"transaction","id":"12513506464","type":"cc","amount":"9.11","currency":"USD",
         "auth_code":"123456","avs_response":"","cvv_response":"M","customer_vault_id":"",
         "status":"pendingsettlement","response":"1","response_text":"SUCCESS","response_code":"100",
         "processor_id":"5326307","created_date":"2026-09-03T19:06:53+00:00",
         "payment_details":{"card_number":"411111******1111","card_exp":"1029","card_type":"Visa"},
         "order_details":{"order_id":"DUP-2155728","ip_address":"107.195.26.1"},
         "actions":[{"id":"4227101093","type":"sale","amount":"9.11","success":true,"response":"1",
                     "response_text":"SUCCESS","response_code":"100","date":"2026-09-03T19:06:53+00:00",
                     "source":"rest_api"}]}
        JSON;

    private const DECLINED_SALE = <<<'JSON'
        {"object":"transaction","id":"12513493102","type":"cc","amount":"0.50","currency":"USD",
         "auth_code":"","cvv_response":"M","status":"failed","response":"2","response_text":"DECLINE",
         "response_code":"200","actions":[{"type":"sale","amount":"0.50","success":false,
         "response":"2","response_text":"DECLINE","response_code":"200"}]}
        JSON;

    public function testItReadsAnApprovedTransaction(): void
    {
        $response = NmiResponse::fromBody(self::APPROVED_SALE);

        self::assertTrue($response->isApproved());
        self::assertSame(NmiResponse::RESULT_APPROVED, $response->result);
        self::assertSame('SUCCESS', $response->responseText);
        self::assertSame(100, $response->responseCode);
        self::assertSame('12513506464', $response->transactionId);
        self::assertSame('123456', $response->authCode);
        self::assertSame('M', $response->cvvResponse);
        self::assertSame('pendingsettlement', $response->status);
        self::assertSame('9.11', $response->amount);
        self::assertSame('USD', $response->currency);
    }

    public function testItReadsADeclinedTransaction(): void
    {
        $response = NmiResponse::fromBody(self::DECLINED_SALE);

        self::assertFalse($response->isApproved());
        self::assertSame(NmiResponse::RESULT_DECLINED, $response->result);
        self::assertSame('DECLINE', $response->responseText);
        self::assertSame(200, $response->responseCode);
        self::assertSame('failed', $response->status);
    }

    public function testAnEmptyStringFieldBecomesNull(): void
    {
        // The gateway sends "" rather than omitting a field it has no value for.
        $response = NmiResponse::fromBody(self::APPROVED_SALE);

        self::assertNull($response->avsResponse);
    }

    public function testItKeepsTheActionsAndTheWholeBody(): void
    {
        $response = NmiResponse::fromBody(self::APPROVED_SALE);

        self::assertCount(1, $response->actions);
        self::assertSame('sale', $response->actions[0]['type']);
        self::assertSame('12513506464', $response->raw['id']);
        self::assertArrayHasKey('payment_details', $response->raw);
    }

    /**
     * The gateway states nothing anywhere in the response about whether a transaction has
     * settled, so this is deliberately not a settlement check.
     */
    #[DataProvider('statuses')]
    public function testWhetherAVoidIsStillPossible(string $status, bool $expected): void
    {
        $body = str_replace('"status":"pendingsettlement"', sprintf('"status":"%s"', $status), self::APPROVED_SALE);

        self::assertSame($expected, NmiResponse::fromBody($body)->isVoidable());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function statuses(): iterable
    {
        yield 'an authorisation' => ['pending', true];
        yield 'a sale or a capture' => ['pendingsettlement', true];
        yield 'already voided' => ['canceled', false];
        yield 'declined' => ['failed', false];
        yield 'a state this plugin has never seen' => ['something_new', false];
    }

    #[DataProvider('bodiesThatAreNotTransactions')]
    public function testABodyThatIsNotATransactionIsRejected(string $body): void
    {
        $this->expectException(NmiGatewayException::class);

        NmiResponse::fromBody($body);
    }

    /** @return iterable<string, array{string}> */
    public static function bodiesThatAreNotTransactions(): iterable
    {
        yield 'empty' => [''];
        yield 'not JSON' => ['<html><body>502 Bad Gateway</body></html>'];
        yield 'JSON but not an object' => ['"ok"'];
        yield 'an object with no response' => ['{"id":"1","status":"pending"}'];
        yield 'a response outside 1-3' => ['{"response":"9"}'];
        yield 'an error body' => ['{"type":"inputError","error_code":"E_INVALID_CC_NUMBER","message":"Invalid Credit Card Number"}'];
    }
}
