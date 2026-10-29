<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Provider;

use JpmMartin\SyliusNmiPlugin\Repository\NmiCardOnFileRepositoryInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiRecurringCredentialRepositoryInterface;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\AfterPayResponseProviderInterface;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\FinalUrlProviderInterface;
use Sylius\Bundle\PaymentBundle\Announcer\PaymentRequestAnnouncerInterface;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfiguration;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Factory\PaymentRequestFactoryInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * Where a shopper lands once their card is on file: the order's confirmation, told that nothing
 * has been charged yet.
 *
 * The platform's own provider cannot say that. It sends every payment that is neither completed nor
 * authorised back to the page that asks for payment, and its message for a waiting payment is
 * "being processed" — true of nothing here. So this answers first, for exactly one kind of payment,
 * and every other one reaches the platform's provider untouched.
 *
 * The status request is still created and announced, as the platform does on every return, so that
 * what a store listens for on the way back does not go quiet on this path.
 *
 * **Both Sylius 2.2 and 2.3.** Sylius 2.3 passes the request where 2.2 passed its configuration; a
 * parameter that takes either satisfies both interfaces, and the request is read off whichever came.
 *
 * @internal
 */
final class NmiCardOnFileAfterPayResponseProvider implements AfterPayResponseProviderInterface
{
    public const FLASH = 'jpm_martin_sylius_nmi.payment.card_put_on_file';

    /** What a held payment that opened recurring charges is told instead: this order, and its renewals. */
    public const FLASH_RECURRING = 'jpm_martin_sylius_nmi.payment.card_kept_for_renewals';

    /**
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository
     * @param PaymentRequestFactoryInterface<PaymentRequestInterface> $paymentRequestFactory
     */
    public function __construct(
        private readonly PaymentRequestRepositoryInterface $paymentRequestRepository,
        private readonly PaymentRequestFactoryInterface $paymentRequestFactory,
        private readonly PaymentRequestAnnouncerInterface $announcer,
        private readonly NmiCardOnFileRepositoryInterface $cardsOnFile,
        private readonly FinalUrlProviderInterface $finalUrlProvider,
        private readonly NmiRecurringCredentialRepositoryInterface $recurringCredentials,
    ) {
    }

    public function supports(RequestConfiguration|Request $request): bool
    {
        $payment = $this->paymentOf(self::requestOf($request));

        return null !== $payment &&
            PaymentInterface::STATE_PROCESSING === $payment->getState() &&
            (null !== $this->cardsOnFile->findHeldBy($payment) || $this->holdsARecurringCredential($payment));
    }

    public function getResponse(RequestConfiguration|Request $request): Response
    {
        $request = self::requestOf($request);
        $previous = $this->previousRequest($request);
        if (null !== $previous) {
            $status = $this->paymentRequestFactory->createFromPaymentRequest($previous);
            $status->setAction(PaymentRequestInterface::ACTION_STATUS);
            $this->paymentRequestRepository->add($status);
            $this->announcer->dispatchPaymentRequestCommand($status);
        }

        if ($request->hasSession()) {
            $session = $request->getSession();
            if ($session instanceof FlashBagAwareSessionInterface) {
                $payment = $previous?->getPayment();
                $session->getFlashBag()->add(
                    'success',
                    $payment instanceof PaymentInterface && $this->holdsARecurringCredential($payment) ? self::FLASH_RECURRING : self::FLASH,
                );
            }
        }

        // Asked with no payment, the platform's provider answers with the final route — the same
        // question its own "nothing to pay" provider asks, and the same answer.
        return new RedirectResponse($this->finalUrlProvider->getUrl(null));
    }

    private function previousRequest(Request $request): ?PaymentRequestInterface
    {
        $hash = $request->attributes->get('hash');
        if (!is_string($hash) || '' === $hash) {
            return null;
        }

        /** @var PaymentRequestInterface|null $paymentRequest */
        $paymentRequest = $this->paymentRequestRepository->find($hash);

        return $paymentRequest;
    }

    /** Held on a recurring credential it opened and the store has not let go. */
    private function holdsARecurringCredential(PaymentInterface $payment): bool
    {
        $credential = $this->recurringCredentials->findOpenedBy($payment);

        return null !== $credential && !$credential->isReleased();
    }

    private function paymentOf(Request $request): ?PaymentInterface
    {
        $payment = $this->previousRequest($request)?->getPayment();

        return $payment instanceof PaymentInterface ? $payment : null;
    }

    /** The request itself, whichever of the two Sylius passed. */
    private static function requestOf(RequestConfiguration|Request $request): Request
    {
        return $request instanceof RequestConfiguration ? $request->getRequest() : $request;
    }
}
