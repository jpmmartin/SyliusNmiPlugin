<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Controller;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiAmountFormatter;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfigurationProviderInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use JpmMartin\SyliusNmiPlugin\Provider\NmiStoredCardOfferInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * What the browser needs in order to authenticate a card the gateway already holds.
 *
 * **This exists so the vault reference does not have to be rendered.** 3-D Secure runs in the
 * browser and there is no server-side form of it; a typed card is authenticated by its token, and
 * a stored card has none, so NMI's own integration authenticates the vault reference instead. That
 * reference is encrypted at rest, and putting it in the page source of every pay-page view would
 * sit oddly beside that — so it is handed over here instead: to the shopper who owns the card,
 * once, at the moment they press Pay, and only when the operator asked for authentication at all.
 *
 * It is not a secret in the sense the security key is. Charging against it needs the private key,
 * which never leaves the server. What this narrows is where it can be read from — page source, a
 * screenshot, a cached page — not what it is worth to somebody who reads it.
 *
 * @internal
 */
final class StoredCardAuthenticationAction
{
    /** @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository */
    public function __construct(
        private readonly PaymentRequestRepositoryInterface $paymentRequestRepository,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly NmiGatewayConfigurationProviderInterface $configurationProvider,
        private readonly NmiStoredCardOfferInterface $storedCardOffer,
        private readonly NmiAmountFormatter $amountFormatter,
    ) {
    }

    public function __invoke(Request $request, string $hash): JsonResponse
    {
        $paymentRequest = $this->paymentRequestRepository->find($hash);
        if (!$paymentRequest instanceof PaymentRequestInterface) {
            throw new NotFoundHttpException(sprintf('No payment request found with hash "%s".', $hash));
        }

        if (NmiGatewayFactory::NAME !== $paymentRequest->getMethod()->getGatewayConfig()?->getFactoryName()) {
            throw new NotFoundHttpException('That payment request does not belong to this gateway.');
        }

        if (PaymentRequestInterface::STATE_PROCESSING !== $paymentRequest->getState()) {
            throw new NotFoundHttpException('That payment request is not waiting for a card.');
        }

        // The same token the charge itself demands, so this cannot be reached from another site.
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(CompleteCardPaymentAction::CSRF_TOKEN_ID_PREFIX . $hash, (string) $request->request->get('_csrf_token')))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $payment = $paymentRequest->getPayment();
        if (!$payment instanceof PaymentInterface) {
            throw new NotFoundHttpException('That payment request has no core payment.');
        }

        $configuration = $this->configurationProvider->fromPaymentMethod($paymentRequest->getMethod());

        // A store that turned authentication off has no use for the reference, so there is no
        // request that can obtain it — the setting is what closes this door, not only what makes
        // the browser skip a step.
        if (!$configuration->authenticateStoredCards) {
            throw new NotFoundHttpException('This payment method does not authenticate stored cards.');
        }

        // The same service that decided what to offer and what may be charged. Somebody else's
        // card, another account's, or an expired one all answer 404 here for the same reason they
        // are refused at the charge: they are not among the cards this payment may be paid with.
        $card = $this->storedCardOffer->chosenFor($payment, $configuration, (string) $request->request->get('stored_card'));
        if (null === $card) {
            throw new NotFoundHttpException('That saved card cannot be used on this payment.');
        }

        $currencyCode = (string) $payment->getCurrencyCode();

        $response = new JsonResponse([
            'customer_vault_id' => $card->getVaultId(),
            // The decimal amount the authentication call wants, formatted here because how many
            // decimals a currency has is not something JavaScript should be deciding.
            'amount' => $this->amountFormatter->format((int) $payment->getAmount(), $currencyCode),
            'currency' => $currencyCode,
        ]);

        // It carries a vault reference: no shared cache, no browser cache, no history entry that
        // outlives the payment.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
