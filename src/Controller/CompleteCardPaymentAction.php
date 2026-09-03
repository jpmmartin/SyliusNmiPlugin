<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Controller;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Bundle\PaymentBundle\Announcer\PaymentRequestAnnouncerInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Where the browser sends the token it got from the gateway.
 *
 * The platform has no route that takes a posted payload for a payment request — its own pay and
 * after-pay routes create requests rather than feed them — so this one exists to carry the token
 * from the card form to the second-phase handler. It does not charge anything itself: it merges
 * what was posted into the request and hands it to the platform's announcer, which is the one
 * path every gateway call goes through.
 */
final class CompleteCardPaymentAction
{
    public const CSRF_TOKEN_ID_PREFIX = 'jpm_martin_sylius_nmi_pay_';

    public const DECLINED_MESSAGE_KEY = 'jpm_martin_sylius_nmi.payment.declined';

    public const FAILED_MESSAGE_KEY = 'jpm_martin_sylius_nmi.payment.failed';

    /**
     * The only fields taken from the request body. Everything else is ignored — the payload is
     * stored encrypted and read back by the charge, and letting a browser put arbitrary keys in
     * it would mean trusting the browser with the shape of a gateway request.
     */
    private const ACCEPTED_FIELDS = [
        'payment_token',
        'cardholder_auth',
        'cavv',
        'xid',
        'eci',
        'three_ds_version',
        'directory_server_id',
    ];

    /** @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository */
    public function __construct(
        private readonly PaymentRequestRepositoryInterface $paymentRequestRepository,
        private readonly PaymentRequestAnnouncerInterface $announcer,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlProviderInterface $afterPayUrlProvider,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, string $hash): Response
    {
        $paymentRequest = $this->paymentRequestRepository->find($hash);
        if (!$paymentRequest instanceof PaymentRequestInterface) {
            throw new NotFoundHttpException(sprintf('No payment request found with hash "%s".', $hash));
        }

        if (NmiGatewayFactory::NAME !== $paymentRequest->getMethod()->getGatewayConfig()?->getFactoryName()) {
            throw new NotFoundHttpException('That payment request does not belong to this gateway.');
        }

        // Only a request waiting for a card can be charged. A finished one reaching here again is
        // a resubmitted form, and charging it a second time is the thing this must never do.
        if (PaymentRequestInterface::STATE_PROCESSING !== $paymentRequest->getState()) {
            throw new NotFoundHttpException('That payment request is not waiting for a card.');
        }

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID_PREFIX . $hash, (string) $request->request->get('_csrf_token')))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $paymentRequest->setPayload($this->payloadFrom($request, $paymentRequest));

        $this->announcer->dispatchPaymentRequestCommand($paymentRequest);

        $this->tellTheShopper($request, $paymentRequest);

        return new RedirectResponse($this->afterPayUrlProvider->getUrl($paymentRequest));
    }

    /**
     * A shopper whose card was refused has to be told why, or the pay page they land back on
     * looks like it simply forgot them.
     */
    private function tellTheShopper(Request $request, PaymentRequestInterface $paymentRequest): void
    {
        if (PaymentRequestInterface::STATE_FAILED !== $paymentRequest->getState()) {
            return;
        }

        $responseData = $paymentRequest->getResponseData();
        $key = is_string($responseData['message_key'] ?? null) ? $responseData['message_key'] : self::FAILED_MESSAGE_KEY;
        $message = $this->translator->trans($key, [], 'flashes');

        // Only a decline carries wording written for the cardholder. Everything else the gateway
        // says is written for the merchant — it can name account configuration — so the shopper
        // gets the generic sentence and the detail stays on the payment request.
        $detail = $responseData['detail'] ?? null;
        if (self::DECLINED_MESSAGE_KEY === $key && is_string($detail) && '' !== $detail) {
            $message = sprintf('%s %s', $message, $detail);
        }

        $session = $request->getSession();
        if ($session instanceof \Symfony\Component\HttpFoundation\Session\Session) {
            $session->getFlashBag()->add('error', $message);
        }
    }

    /** @return array<string, mixed> */
    private function payloadFrom(Request $request, PaymentRequestInterface $paymentRequest): array
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($paymentRequest->getPayload()) ? $paymentRequest->getPayload() : [];

        foreach (self::ACCEPTED_FIELDS as $field) {
            $value = $request->request->get($field);
            if (is_string($value) && '' !== trim($value)) {
                $payload[$field] = trim($value);
            }
        }

        // Taken from the connection, never from the body: a shopper's browser must not be able to
        // choose the address the gateway sees.
        $clientIp = $request->getClientIp();
        if (null !== $clientIp) {
            $payload['ip_address'] = $clientIp;
        }

        return $payload;
    }
}
