<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Unit\Gateway;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiErrorResponse;
use PHPUnit\Framework\TestCase;

/** Bodies taken verbatim from the gateway. */
final class NmiErrorResponseTest extends TestCase
{
    public function testItReadsARefusedRequest(): void
    {
        $error = NmiErrorResponse::fromBody(400, '{"type":"inputError","error_code":"E_INVALID_TRANS_SPECIFIED","message":"Refunded transactions may not be voided","ref_id":"186208620"}');

        self::assertNotNull($error);
        self::assertSame(400, $error->httpStatus);
        self::assertSame(NmiErrorResponse::TYPE_INPUT, $error->type);
        self::assertSame('E_INVALID_TRANS_SPECIFIED', $error->errorCode);
        self::assertSame('186208620', $error->referenceId);
        self::assertSame('Refunded transactions may not be voided', $error->describe());
    }

    /** A schema violation is the one shape that carries no error code, so nothing may require one. */
    public function testItReadsASchemaViolationAndNamesTheField(): void
    {
        $error = NmiErrorResponse::fromBody(422, '{"type":"validationError","message":"The provided data is invalid.","details":[{"fieldName":"amount","message":"The value cannot be empty"}]}');

        self::assertNotNull($error);
        self::assertSame(422, $error->httpStatus);
        self::assertNull($error->errorCode);
        self::assertNull($error->referenceId);
        self::assertSame('The provided data is invalid. (amount: The value cannot be empty)', $error->describe());
    }

    public function testItReadsAnAuthenticationFailure(): void
    {
        $error = NmiErrorResponse::fromBody(401, '{"type":"authenticationError","error_code":"E_AUTHENTICATION_MISSING","message":"Missing\/Invalid Authentication","ref_id":"186212713"}');

        self::assertNotNull($error);
        self::assertSame(NmiErrorResponse::TYPE_AUTHENTICATION, $error->type);
        self::assertSame('Missing/Invalid Authentication', $error->message);
    }

    public function testABodyThatIsNotAnErrorIsNotOne(): void
    {
        self::assertNull(NmiErrorResponse::fromBody(502, '<html>502 Bad Gateway</html>'));
        self::assertNull(NmiErrorResponse::fromBody(400, ''));
        self::assertNull(NmiErrorResponse::fromBody(400, '{"message":"no type"}'));
    }
}
