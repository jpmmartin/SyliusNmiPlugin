<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway\Exception;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiErrorResponse;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;

/**
 * The gateway answered, but with an error rather than a decision: a rejected request, a
 * processor error, a configuration problem, an unparsable body, or an unusable stored
 * configuration. This is the merchant's problem, never the shopper's.
 *
 * A refusal carries the gateway's own message. That message is the only description of some
 * refusals — an authorisation the gateway will no longer capture has no code of its own — so
 * it is preserved verbatim and shown to the operator rather than translated into a guess.
 */
final class NmiGatewayException extends \RuntimeException implements NmiExceptionInterface
{
    private function __construct(
        string $message,
        private readonly ?NmiResponse $response = null,
        private readonly ?NmiErrorResponse $error = null,
        private readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(NmiResponse $response): self
    {
        return new self(
            sprintf('The gateway rejected the transaction (%d): %s', $response->responseCode, $response->responseText),
            $response,
        );
    }

    public static function fromError(NmiErrorResponse $error): self
    {
        return new self(
            sprintf('The gateway refused the request (HTTP %d, %s): %s', $error->httpStatus, $error->errorCode ?? $error->type ?? 'no code', $error->describe()),
            null,
            $error,
        );
    }

    public static function fromHttpStatus(int $status): self
    {
        return new self(sprintf('The gateway answered with HTTP status %d.', $status), null, null, $status);
    }

    public static function malformedResponse(): self
    {
        return new self('The gateway answered with a body that is not a transaction response.');
    }

    public static function configuration(string $message): self
    {
        return new self($message);
    }

    public function getResponse(): ?NmiResponse
    {
        return $this->response;
    }

    public function getError(): ?NmiErrorResponse
    {
        return $this->error;
    }

    public function getHttpStatus(): ?int
    {
        return $this->error?->httpStatus ?? $this->httpStatus;
    }

    /**
     * Whether the gateway says it has no such record.
     *
     * A 404 on a delete is the state being asked for rather than a failure: deleting a vault
     * record twice answers 204 and then 404 with `E_RESOURCE_NOT_FOUND`, established against the
     * gateway. Everything that removes a vault record reads this, so the two callers cannot come
     * to different conclusions about the same status.
     */
    public function isAlreadyGone(): bool
    {
        return 404 === $this->getHttpStatus();
    }

    /**
     * The gateway's own wording for a refusal, or null when there is none. Suitable for
     * showing to an operator; never to a shopper, because it can name merchant configuration.
     */
    public function getGatewayMessage(): ?string
    {
        return $this->error?->describe() ?? $this->response?->responseText;
    }
}
