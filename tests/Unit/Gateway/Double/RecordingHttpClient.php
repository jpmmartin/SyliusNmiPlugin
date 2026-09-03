<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Unit\Gateway\Double;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** A PSR-18 client that never touches the network: it records the request and answers as told. */
final class RecordingHttpClient implements ClientInterface
{
    public ?RequestInterface $lastRequest = null;

    private ?ResponseInterface $response = null;

    private ?ClientExceptionInterface $exception = null;

    public function __construct(private readonly Psr17Factory $factory)
    {
    }

    public function willAnswer(int $status, string $body): void
    {
        $this->response = $this->factory->createResponse($status)->withBody($this->factory->createStream($body));
        $this->exception = null;
    }

    public function willThrow(ClientExceptionInterface $exception): void
    {
        $this->exception = $exception;
        $this->response = null;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;

        if (null !== $this->exception) {
            throw $this->exception;
        }

        return $this->response ?? throw new \LogicException('No answer was prepared.');
    }

    /** @return array<string, mixed> The JSON body of the last request, decoded */
    public function lastBodyJson(): array
    {
        $decoded = json_decode((string) $this->lastRequest?->getBody(), true);

        /** @var array<string, mixed> $body */
        $body = is_array($decoded) ? $decoded : [];

        return $body;
    }

    public function lastBodyRaw(): string
    {
        return (string) $this->lastRequest?->getBody();
    }
}
