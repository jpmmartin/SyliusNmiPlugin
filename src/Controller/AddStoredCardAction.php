<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Controller;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiExceptionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiClientInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfigurationProviderInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\VaultCard;
use JpmMartin\SyliusNmiPlugin\Provider\NmiVaultingPaymentMethodProvider;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiStoredCardRecorderInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Storing a card without buying anything.
 *
 * The browser tokenises the card exactly as it does on the pay page — the same component, the same
 * mount point, the same posted token — and this hands that token to the gateway's own vault call.
 * Nothing is charged: that call answers with a customer rather than a transaction, so there is no
 * amount and no authorisation for a statement to show.
 *
 * The customer comes from the customer context and never from the request. The route is behind the
 * shop's ROLE_USER rule, so there is no anonymous path in here at all.
 */
final class AddStoredCardAction
{
    private const TEMPLATE = '@JpmMartinSyliusNmiPlugin/shop/account/stored_card/add.html.twig';

    /**
     * The shape a brand must have before it is believed.
     *
     * Letters, spaces and a little punctuation, and short — **no digits at all**. Every card
     * network is a word: visa, mastercard, amex, discover, jcb, diners, unionpay, maestro. Allowing
     * digits let sixteen of them through as a "brand" and into the column, which a test caught; it
     * is the same hole 3.4 closed on the paying side, in a field added later.
     *
     * This is the only thing the browser is trusted for on this page, and it is trusted for it
     * because the gateway does not answer with it — while the digits and the expiry, which are what
     * a shopper recognises a card by, still come from the gateway's own reply.
     */
    private const BRAND_SHAPE = '/^[\p{L} .\-]{1,32}$/u';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly ChannelContextInterface $channelContext,
        private readonly NmiVaultingPaymentMethodProvider $paymentMethodProvider,
        private readonly NmiGatewayConfigurationProviderInterface $configurationProvider,
        private readonly NmiClientInterface $client,
        private readonly NmiStoredCardRecorderInterface $recorder,
        private readonly ObjectManager $manager,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $customer = $this->customerContext->getCustomer();
        $channel = $this->channelContext->getChannel();

        // The customer context speaks the Customer component's interface; a stored card belongs to
        // the core one, which is the only kind a shop user has. Anything else has no account here.
        if (!$customer instanceof CustomerInterface || !$channel instanceof ChannelInterface) {
            throw new NotFoundHttpException('There is no signed-in customer to store a card for.');
        }

        $paymentMethod = $this->paymentMethodProvider->forChannel($channel);
        if (null === $paymentMethod) {
            // Either the store saves no cards, or it has more than one NMI account and nothing
            // here may choose between them on the shopper's behalf.
            throw new NotFoundHttpException('This channel has no single NMI account that stores cards.');
        }

        $configuration = $this->configurationProvider->fromPaymentMethod($paymentMethod);

        if (!$request->isMethod(Request::METHOD_POST)) {
            return new Response($this->twig->render(self::TEMPLATE, [
                'tokenization_key' => $configuration->tokenizationKey,
                'customer' => $customer,
            ]));
        }

        $token = $request->request->get('payment_token');
        if (!is_string($token) || '' === trim($token)) {
            // The page posts to itself only after the component produced a token, so an empty one
            // is a page that was submitted some other way rather than a shopper who made a mistake.
            return $this->backToTheList($request, 'jpm_martin_sylius_nmi.stored_card.no_token', 'error');
        }

        try {
            $record = $this->client->createVaultRecord($configuration, new VaultCard(trim($token), brand: $this->brandFrom($request)));
        } catch (NmiGatewayException $exception) {
            // The gateway's own sentence about the card, which is the only description some
            // refusals have — the same reasoning the charge path already follows.
            return $this->backToTheList($request, $exception->getGatewayMessage() ?? 'jpm_martin_sylius_nmi.stored_card.rejected', 'error');
        } catch (NmiExceptionInterface) {
            return $this->backToTheList($request, 'jpm_martin_sylius_nmi.stored_card.gateway_unreachable', 'error');
        }

        $card = $this->recorder->recordVaulted($customer, $paymentMethod, $record);

        // The recorder persists without flushing, because its other caller runs inside the payment
        // request bus's own transaction. This one does not, so the commit belongs here.
        $this->manager->flush();

        return $this->backToTheList(
            $request,
            null === $card
                ? 'jpm_martin_sylius_nmi.stored_card.not_described'
                : 'jpm_martin_sylius_nmi.stored_card.added',
            null === $card ? 'error' : 'success',
        );
    }

    /**
     * The brand the browser reported, or null when it reported nothing this will believe.
     *
     * Null is not fatal on its own — it only becomes fatal if the gateway is silent too, which it
     * is on this endpoint. That is why it is worth sending: without it the card cannot be
     * described, and a card that cannot be described is a vault record with nothing pointing at it.
     */
    private function brandFrom(Request $request): ?string
    {
        $brand = $request->request->get('card_brand');

        if (!is_string($brand)) {
            return null;
        }

        $brand = trim($brand);

        return 1 === preg_match(self::BRAND_SHAPE, $brand) ? $brand : null;
    }

    private function backToTheList(Request $request, string $message, string $type): RedirectResponse
    {
        // Translated here rather than by the template: the gateway's own sentence passes through
        // untouched, because the catalogue returns anything it does not recognise unchanged.
        $session = $request->getSession();
        if ($session instanceof Session) {
            $session->getFlashBag()->add($type, $this->translator->trans($message, [], 'flashes'));
        }

        return new RedirectResponse(
            $this->urlGenerator->generate('jpm_martin_sylius_nmi_shop_account_stored_card_index'),
        );
    }
}
