<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Provider;

use Sylius\Bundle\PaymentBundle\Provider\HttpResponseProviderInterface;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfiguration;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Renders the card form on the platform's pay page.
 *
 * The pay page announces the request's command *before* asking for a response, so by the time
 * this is reached the first-phase handler has already run and left everything the browser needs
 * on the request. Only a request in progress gets a form: one that has finished has nothing left
 * to collect, and returning no response lets the platform send the shopper on to its own
 * after-pay page rather than showing a card form for a payment that is already settled.
 */
final class NmiHttpResponseProvider implements HttpResponseProviderInterface
{
    public const TEMPLATE = '@JpmMartinSyliusNmiPlugin/shop/pay/nmi.html.twig';

    private const HANDLED_ACTIONS = [
        PaymentRequestInterface::ACTION_CAPTURE,
        PaymentRequestInterface::ACTION_AUTHORIZE,
    ];

    public function __construct(private readonly Environment $twig)
    {
    }

    public function supports(
        RequestConfiguration $requestConfiguration,
        PaymentRequestInterface $paymentRequest,
    ): bool {
        return PaymentRequestInterface::STATE_PROCESSING === $paymentRequest->getState() &&
            in_array($paymentRequest->getAction(), self::HANDLED_ACTIONS, true);
    }

    public function getResponse(
        RequestConfiguration $requestConfiguration,
        PaymentRequestInterface $paymentRequest,
    ): Response {
        return new Response($this->twig->render(self::TEMPLATE, [
            'payment_request' => $paymentRequest,
            'nmi' => $paymentRequest->getResponseData(),
        ]));
    }
}
