<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway\Exception;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;

/** The gateway answered and the issuer said no. This is the shopper's problem to fix. */
final class NmiDeclinedException extends \RuntimeException implements NmiExceptionInterface
{
    public function __construct(private readonly NmiResponse $response)
    {
        parent::__construct(sprintf('The gateway declined the transaction (%d): %s', $response->responseCode, $response->responseText));
    }

    public function getResponse(): NmiResponse
    {
        return $this->response;
    }

    /** The issuer's wording, suitable for showing to the shopper. */
    public function getDeclineReason(): string
    {
        return $this->response->responseText;
    }
}
