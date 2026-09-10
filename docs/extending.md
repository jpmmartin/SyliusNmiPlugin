# Extending the plugin

This page names every seam a store, a theme or another plugin may rely on. **What is named here
is the plugin's public surface**, and the versioning promise in [upgrading.md](upgrading.md) is
made about it: a change to any of it is a breaking change and ships as a major release. Everything
not named here carries `@internal` in the code and may change in a minor release. A test holds
both halves to the code — every name below exists, and every class under `src/` is either named
below or marked internal — so this page cannot quietly go stale.

The seams are the ones Sylius and Symfony already give you: Twig hooks for what is shown,
decorated services for what is done, workflow events for reacting, routes and the API for
integrating, and DOM events for the browser. Nothing here needs a fork.

## What is shown: hooks, hookables and templates

Every screen the plugin adds is rendered through Twig hooks. Override a hookable's template,
change its priority, add one of your own or disable one in your store's `sylius_twig_hooks`
configuration, the way the Sylius documentation describes.

| Hook | Hookables |
|---|---|
| `jpm_martin_sylius_nmi.shop.pay` — the pay page | `stored_cards`, `card_form`, `save_card`, `pay_button`, `three_d_secure` |
| `jpm_martin_sylius_nmi.shop.account.stored_card.index.content` | `menu`, `main` |
| `jpm_martin_sylius_nmi.shop.account.stored_card.index.content.main` | `cards` |
| `jpm_martin_sylius_nmi.shop.account.stored_card.index.content.main.header` | `title`, `buttons` |
| `jpm_martin_sylius_nmi.shop.account.stored_card_add.index.content` | `menu`, `main` |
| `jpm_martin_sylius_nmi.shop.account.stored_card_add.index.content.main` | `form` |
| `jpm_martin_sylius_nmi.shop.account.stored_card_add.index.content.main.header` | `title` |
| `sylius_admin.order.show.content.sections.payments.item.actions` | `nmi_void`, the *Void* action on a payment row |
| `sylius_admin.payment_method.create.content.form.sections.gateway_configuration.nmi` | `fields` |
| `sylius_admin.payment_method.update.content.form.sections.gateway_configuration.nmi` | `fields` |

Every template under the plugin's `templates/` directory can be overridden by path, under your
store's `templates/bundles/JpmMartinSyliusNmiPlugin/`. The pay page's five fragments live in
`templates/shop/pay/nmi/`; keep the `data-nmi-*` attributes described below when you override
one, because the script finds its parts by them.

## What is done: services you may decorate

Each of these is reachable by its interface — autowire it, or decorate it by the service id it is
aliased to. The implementation behind each is internal.

| Interface | Service | What it decides |
|---|---|---|
| `JpmMartin\SyliusNmiPlugin\Gateway\Request\ChargeFactoryInterface` | `jpm_martin_sylius_nmi.gateway.charge_factory` | the charge sent to the gateway for a token or a stored card — see below |
| `JpmMartin\SyliusNmiPlugin\Gateway\NmiClientInterface` | `jpm_martin_sylius_nmi.gateway.client` | every call to the gateway's API |
| `JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfigurationProviderInterface` | `jpm_martin_sylius_nmi.gateway.configuration_provider` | the keys and host read off a payment method |
| `JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface` | `jpm_martin_sylius_nmi.recorder.transaction` | how a gateway transaction is written to the log |
| `JpmMartin\SyliusNmiPlugin\Recorder\NmiGatewayNoticeRecorderInterface` | `jpm_martin_sylius_nmi.recorder.gateway_notice` | how a chargeback or settlement failure becomes a notice |
| `JpmMartin\SyliusNmiPlugin\Recorder\NmiStoredCardRecorderInterface` | `jpm_martin_sylius_nmi.recorder.stored_card` | how a card the gateway kept is filed for a customer |
| `JpmMartin\SyliusNmiPlugin\Provider\NmiCardSavingCustomerProviderInterface` | `jpm_martin_sylius_nmi.provider.card_saving_customer` | who may save a card out of a payment |
| `JpmMartin\SyliusNmiPlugin\Provider\NmiStoredCardOfferInterface` | `jpm_martin_sylius_nmi.provider.stored_card_offer` | which saved cards a payment is offered |
| `JpmMartin\SyliusNmiPlugin\Webhook\NmiWebhookRouterInterface` | `jpm_martin_sylius_nmi.webhook.router` | which handler a webhook event reaches |
| `JpmMartin\SyliusNmiPlugin\Webhook\NmiReceivedEventLedgerInterface` | `jpm_martin_sylius_nmi.webhook.ledger` | how a delivery is remembered so it runs once |
| `JpmMartin\SyliusNmiPlugin\Webhook\NmiStoredCardLocatorInterface` | `jpm_martin_sylius_nmi.webhook.stored_card_locator` | how a card update finds the card it is about |
| `JpmMartin\SyliusNmiPlugin\Webhook\NmiCardUpdateApplierInterface` | `jpm_martin_sylius_nmi.webhook.card_update_applier` | what a card update does to the card |
| `JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface` | `jpm_martin_sylius_nmi.repository.nmi_transaction` | the transaction log |
| `JpmMartin\SyliusNmiPlugin\Repository\NmiGatewayNoticeRepositoryInterface` | `jpm_martin_sylius_nmi.repository.nmi_gateway_notice` | the notices |
| `JpmMartin\SyliusNmiPlugin\Repository\NmiReceivedEventRepositoryInterface` | `jpm_martin_sylius_nmi.repository.nmi_received_event` | the webhook deliveries |
| `JpmMartin\SyliusNmiPlugin\Repository\NmiStoredCardRepositoryInterface` | `jpm_martin_sylius_nmi.repository.nmi_stored_card` | the saved cards |

### Telling the gateway more: the charge factory

The plugin sends the gateway the amount, the currency, the order's number, the shopper's address,
the 3-D Secure result and how the money is named. It leaves the order description and the billing
details empty — a billing address handed to the gateway can trip a store's own address-verification
rules — and knows nothing of descriptors, merchant-defined fields or level-3 data. All of that is
yours to add by decorating the factory: call the inner one, then either rebuild the
`JpmMartin\SyliusNmiPlugin\Gateway\Request\Charge` with the fields it models, or hand any field of
NMI's Payments API through `with()`.

```php
namespace App\Payment;

use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\Charge;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\ChargeFactoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

#[AsDecorator('jpm_martin_sylius_nmi.gateway.charge_factory')]
final class DescribedChargeFactory implements ChargeFactoryInterface
{
    public function __construct(private readonly ChargeFactoryInterface $inner)
    {
    }

    public function forToken(PaymentInterface $payment, string $token, array $payload, bool $storeCard): Charge
    {
        return $this->inner->forToken($payment, $token, $payload, $storeCard)->with($this->more($payment));
    }

    public function forStoredCard(PaymentInterface $payment, NmiStoredCardInterface $card, array $payload): Charge
    {
        return $this->inner->forStoredCard($payment, $card, $payload)->with($this->more($payment));
    }

    /** @return array<string, mixed> */
    private function more(PaymentInterface $payment): array
    {
        return [
            'order_details' => ['order_description' => sprintf('Order %s', $payment->getOrder()?->getNumber())],
            'merchant_defined_fields' => ['1' => (string) $payment->getOrder()?->getChannel()?->getCode()],
        ];
    }
}
```

What `with()` carries is merged *beneath* the plugin's own fields: where both name the same key the
plugin's value is sent, so a decorator cannot change the amount, the currency or the token, and
where both hold an object the objects merge, so `order_details` gains your keys next to the
plugin's `id`. The plugin validates none of it. A field the gateway refuses is refused like any
other charge: the payment stays retryable, the shopper sees the generic failure, and the log
carries the gateway's reason.

### Value objects, exceptions and constants you may use

`JpmMartin\SyliusNmiPlugin\Gateway\Request\Charge`,
`JpmMartin\SyliusNmiPlugin\Gateway\Request\BillingDetails`,
`JpmMartin\SyliusNmiPlugin\Gateway\Request\ThreeDSecureResult`,
`JpmMartin\SyliusNmiPlugin\Gateway\Request\StoredCard` and
`JpmMartin\SyliusNmiPlugin\Gateway\Request\VaultCard` are what a charge is made of;
`JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse`, `JpmMartin\SyliusNmiPlugin\Gateway\NmiErrorResponse`
and `JpmMartin\SyliusNmiPlugin\Gateway\NmiVaultRecord` are what the gateway answers;
`JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfiguration` is a payment method's credentials and
`JpmMartin\SyliusNmiPlugin\Gateway\NmiCardDetails` a card as the browser described it. The client
throws `JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiDeclinedException`,
`JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException` and
`JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException`, all of them
`JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiExceptionInterface`; the refund-plugin handler
throws `JpmMartin\SyliusNmiPlugin\Refund\Exception\RefundNotPerformed`.

`JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory` holds the gateway factory name, `nmi`, and
the keys of the gateway configuration as stored; `JpmMartin\SyliusNmiPlugin\Refund\RefundPaymentTransitions`
the `confirm_gateway_refund` transition the plugin adds to the refund plugin's refund-payment
workflow; `JpmMartin\SyliusNmiPlugin\Mailer\NmiEmails` the code of the email it sends.

The four resources — `nmi_transaction`, `nmi_gateway_notice`, `nmi_received_event`,
`nmi_stored_card` — are Sylius resources configured under `jpm_martin_sylius_nmi.resources`, with
`JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface`,
`JpmMartin\SyliusNmiPlugin\Entity\NmiGatewayNoticeInterface`,
`JpmMartin\SyliusNmiPlugin\Entity\NmiReceivedEventInterface` and
`JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface` as their contracts and
`JpmMartin\SyliusNmiPlugin\Entity\NmiTransaction`, `JpmMartin\SyliusNmiPlugin\Entity\NmiGatewayNotice`,
`JpmMartin\SyliusNmiPlugin\Entity\NmiReceivedEvent` and `JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCard`
as the models a store may extend the Sylius way. The forms a store may extend are
`JpmMartin\SyliusNmiPlugin\Form\Type\NmiGatewayConfigurationType` and
`JpmMartin\SyliusNmiPlugin\Form\Type\NmiStoredCardDefaultType`; the bundle class is
`JpmMartin\SyliusNmiPlugin\JpmMartinSyliusNmiPlugin`.

## Reacting: what the plugin does to Sylius's state machines

The plugin adds no events of its own. What it does, it does through transitions Sylius already
publishes events for, so react where every other listener reacts:

- on a payment (`sylius_payment`): `authorize`, `complete`, `cancel` (a void) and `refund`;
- on a payment request (`sylius_payment_request`): `process`, `complete` and `fail`;
- with the refund plugin, on a refund payment (`sylius_refund_refund_payment`): its own
  `confirm_gateway_refund`, and a guard that blocks `complete` for NMI refund payments.

It listens on `sylius.payment.pre_complete`, `sylius.payment.pre_cancel` and
`sylius.payment.pre_refund` to perform the capture, the void and the refund, and on
`workflow.sylius_shipment.completed.ship` to capture when a shipment goes out. The transaction log
is written through the recorder above; decorate it to react to a transaction being recorded.

## Integrating: routes, the API and the console

| Route | Path | What it is |
|---|---|---|
| `jpm_martin_sylius_nmi_shop_complete_card_payment` | `/nmi/pay/{hash}` | where the browser posts a token or a saved card for a payment request |
| `jpm_martin_sylius_nmi_shop_stored_card_authentication` | `/nmi/pay/{hash}/authenticate` | what the browser asks for to authenticate a saved card |
| `jpm_martin_sylius_nmi_shop_account_stored_card_index` | `/saved-cards` | the shopper's saved cards |
| `jpm_martin_sylius_nmi_shop_account_stored_card_add` | `/saved-cards/add` | saving a card without paying |
| `jpm_martin_sylius_nmi_shop_account_stored_card_set_default` | `/saved-cards/{id}/default` | choosing the default card |
| `jpm_martin_sylius_nmi_shop_account_stored_card_delete` | `/saved-cards/{id}` | forgetting a card |
| `jpm_martin_sylius_nmi_admin_order_payment_void` | `/orders/{orderId}/payments/{id}/void` | the *Void* action |
| `jpm_martin_sylius_nmi_admin_gateway_notice_index` | `/nmi-notices` | the gateway notices |
| `jpm_martin_sylius_nmi_webhook` | `/nmi/webhooks/{code}` | where NMI delivers |

The account and admin routes are mounted under the prefixes the README's step 3 gives them. The
headless flow needs none of these: it is Sylius's own payment-request API, documented in the
README under *Headless*. The console command `jpm-martin:sylius-nmi:prune-received-events` prunes
the webhook deliveries.

## The browser: the card form's contract

The store compiles the plugin's script with its own Encore, so your own entry can import from it:

```js
import { mount, mountAll, submit } from '../../vendor/jpmmartin/sylius-nmi-plugin/assets/shop/js/nmi-payment.js';
```

The page still mounts itself on load; `mount` refuses to mount the same element twice, so calling
it as well changes nothing. **One card form per page**: the gateway's Collect.js configures once
per page, and a second element carrying `data-nmi-payment` is left unmounted with a console
warning.

### Attributes

The script finds every part by attribute — inside the container first, on the page second, since
on the plugin's own pay page the button and the 3-D Secure element are sibling hookables of the
form. Render them anywhere, in any markup, and keep the attributes.

| Attribute | On | Meaning |
|---|---|---|
| `data-nmi-payment` | the card form's container | the element to mount; everything below hangs off it |
| `data-nmi-mode` | the container | `submit` (default) posts the token; `tokenize` hands it over and posts nothing |
| `data-nmi-tokenization-key` | the container | the method's public key, read off `gatewayConfig.config.tokenization_key` |
| `data-nmi-amount`, `data-nmi-currency`, `data-nmi-amount-major` | the container | what is charged, in minor units, and its decimal form for the issuer |
| `data-nmi-action-url`, `data-nmi-csrf-token` | the container | where `submit` posts, and the token that proves the shopper asked; not needed in `tokenize` mode |
| `data-nmi-first-name`, `data-nmi-last-name`, `data-nmi-email`, `data-nmi-address1`, `data-nmi-city`, `data-nmi-postal-code`, `data-nmi-state`, `data-nmi-country`, `data-nmi-phone` | the container | what the issuer is told about the shopper; the more, the fewer challenges |
| `data-nmi-error-message`, `data-nmi-unavailable-message`, `data-nmi-auth-failed-message` | the container | the three sentences the form may show, translated by the template |
| `data-nmi-describe-card` | the container | ask the browser to send the card's brand, which the account's add-a-card page needs |
| `data-nmi-field`, `data-nmi-title`, `data-nmi-placeholder` | each field element | which of `ccnumber`, `ccexp`, `cvv` the gateway draws there, its accessible name and its placeholder |
| `data-nmi-pay-button` | the button that starts an attempt | disabled until the frames are ready |
| `data-nmi-error` | an element | where a failed attempt is said out loud |
| `data-nmi-three-d-secure` | an element | where the authentication widget attaches; one per page |
| `data-nmi-store-card` | a checkbox | the shopper's request to keep the card, read when the token arrives |
| `data-nmi-new-card-only` | anything | put away while a saved card is chosen |
| `data-nmi-stored-cards`, `data-nmi-authenticate-url`, `data-nmi-authenticate` | the saved-cards block | the block, where the browser asks for what a saved card's authentication needs, and whether to ask at all |
| `data-nmi-payment-source` | the radios in that block | the chosen card, or `new` |
| `data-nmi-stored-card-pay`, `data-nmi-stored-card-error` | in that block | the saved-card path's own button and error line |

### Events

All four are `CustomEvent`s dispatched on the container and bubbling, with these `detail`s:

| Event | `detail` | When |
|---|---|---|
| `nmi:mounted` | `{}` | the gateway's frames take input and the button is enabled |
| `nmi:token` | `{ token, authentication, fields }` | `tokenize` mode only: the token, the 3-D Secure result under the names the store expects, and `fields`, which is what `submit` would post minus the CSRF token |
| `nmi:submitted` | `{ fields }` — cancelable | just before the hidden form posts; `preventDefault()` keeps it from posting |
| `nmi:failed` | `{ reason, message }` | the attempt ended without a token: `unreadable` (a field the gateway refused, or no answer), `unavailable` (the frames never came), `not_authenticated` (3-D Secure failed, was abandoned or timed out). The message is the one shown to the shopper |

A decline is never an event: the token is posted as a form, the store answers with a redirect and a
flash message, and the page that shows the decline is the next page.

### Functions

- `mount(container)` — mounts the card form on the element; the page's own mounting already did
  it for every `data-nmi-payment` element present at load.
- `mountAll()` — what the page does on load: every card form and every saved-cards block.
- `submit(container, fields)` — posts `fields` with the container's CSRF token to its action URL,
  after a cancelable `nmi:submitted`; returns `false` when a listener cancelled it. This is how a
  token produced elsewhere reaches the pay page: keep the `fields` of an `nmi:token` event and
  hand them here.

## Recipe: the card form on the checkout's summary step

Run on Sylius 2.2.9 with this plugin, on a store with the shop's default theme, on 2026-09-10:
order 000000028 was paid with the sandbox's frictionless card typed on the summary step, and the
shopper never saw the pay page's form. Nothing below touches the plugin: four store-side files,
and the seams named on this page.

The card is asked for on the **summary step** and not earlier because that is the last step
before the order exists and the first where the total is final: 3-D Secure authenticates a token
for an amount, and the amount the pay page charges is the order's. The token itself is single-use
and, per NMI, valid for 24 hours unused, so the redirect after *Place order* is no risk to it.

**1. A hookable on the summary step, and one on the pay page**, in the store's
`config/packages/twig_hooks.yaml` — priority 50 puts the card between the order summary and the
navigation:

```yaml
sylius_twig_hooks:
    hooks:
        # The store's recipe: the card on the checkout's summary step, handed to the pay page.
        'sylius_shop.checkout.complete.content.form':
            nmi_card:
                template: 'shop/checkout/complete/nmi_card.html.twig'
                priority: 50
        'jpm_martin_sylius_nmi.shop.pay':
            handoff:
                template: 'shop/pay/nmi_handoff.html.twig'
                priority: 1000
```

**2. The summary-step template**, `templates/shop/checkout/complete/nmi_card.html.twig`: the
plugin's container in `tokenize` mode, its attributes read off the order, the three fields, an
error line, and a *Verify card* button. Every value the issuer is told comes from the order's
billing address; the tokenization key is the payment method's, readable in Twig because Sylius
decrypts the configuration on load.

```twig
{# The card, asked for on the summary step. Store-side: nothing here is the plugin's. #}
{% set order = hookable_metadata.context.form.vars.value %}
{% set payment = order.lastPayment %}
{% set method = payment ? payment.method : null %}
{% if method and method.gatewayConfig.factoryName == 'nmi' %}
    {% set config = method.gatewayConfig.config %}
    {% set address = order.billingAddress %}
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h5 mb-3">Your card</h2>
            <div
                data-nmi-payment
                data-nmi-mode="tokenize"
                data-nmi-tokenization-key="{{ config.tokenization_key }}"
                data-nmi-amount="{{ order.total }}"
                data-nmi-currency="{{ order.currencyCode }}"
                data-nmi-amount-major="{{ (order.total / 100)|number_format(2, '.', '') }}"
                {% if address %}
                    data-nmi-first-name="{{ address.firstName }}"
                    data-nmi-last-name="{{ address.lastName }}"
                    data-nmi-address1="{{ address.street }}"
                    data-nmi-city="{{ address.city }}"
                    data-nmi-postal-code="{{ address.postcode }}"
                    data-nmi-country="{{ address.countryCode }}"
                    {% if address.phoneNumber %}data-nmi-phone="{{ address.phoneNumber }}"{% endif %}
                {% endif %}
                {% if order.customer %}data-nmi-email="{{ order.customer.email }}"{% endif %}
                data-nmi-error-message="The card details could not be read."
                data-nmi-auth-failed-message="The card could not be authenticated."
                data-nmi-unavailable-message="The card form could not be loaded."
            >
                <div class="row">
                    <div class="col-12 col-md-8">
                        <div class="mb-3">
                            <label class="form-label">Card number</label>
                            <div data-nmi-field="ccnumber" data-nmi-title="Card number" data-nmi-placeholder="0000 0000 0000 0000"></div>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Expiry date</label>
                                <div data-nmi-field="ccexp" data-nmi-title="Expiry date" data-nmi-placeholder="MM / YY"></div>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Security code</label>
                                <div data-nmi-field="cvv" data-nmi-title="Security code" data-nmi-placeholder="123"></div>
                            </div>
                        </div>
                        <p data-nmi-error class="text-danger" role="alert" hidden></p>
                        <button type="button" class="btn btn-outline-primary" data-nmi-pay-button disabled>Verify card</button>
                        <p class="text-success mt-2" data-store-card-verified hidden>Card verified. You can place your order.</p>
                    </div>
                </div>
            </div>
            <div data-nmi-three-d-secure></div>
        </div>
    </div>
{% endif %}
```

**3. The pay-page notice**, `templates/shop/pay/nmi_handoff.html.twig`, shown by the script
while it hands the card over:

```twig
{# Shown by the store's script while it hands the pay page the card verified on the summary step. #}
<p class="alert alert-info" data-store-nmi-handoff hidden>Completing your payment with the card you verified.</p>
```

**4. The store's script**, appended to `assets/shop/entrypoint.js` and compiled by the store's
own Encore. On the summary step it keeps what `nmi:token` announces and holds *Place order* until
a card is verified; on the pay page it hides the form, shows the notice and hands the kept fields
to `submit`:

```js
// ── The card on the summary step, handed to the pay page ─────────────────────────────────────
// Store-side: listens to what the plugin's form announces, keeps the token for the pay page,
// and hands it over there with the plugin's own `submit`.
import { submit as nmiSubmit } from '../../vendor/jpmmartin/sylius-nmi-plugin/assets/shop/js/nmi-payment.js';

const NMI_KEPT = 'nmi-verified-card';

document.addEventListener('DOMContentLoaded', () => {
    // Summary step: Place order waits for a verified card.
    const summaryForm = document.querySelector('[data-nmi-payment][data-nmi-mode="tokenize"]');
    if (summaryForm) {
        const placeOrder = document.querySelector('form[name="sylius_checkout_complete"] button[type="submit"]');
        if (placeOrder) {
            placeOrder.disabled = true;
        }
        summaryForm.addEventListener('nmi:token', (event) => {
            sessionStorage.setItem(NMI_KEPT, JSON.stringify(event.detail.fields));
            summaryForm.querySelector('[data-store-card-verified]').hidden = false;
            if (placeOrder) {
                placeOrder.disabled = false;
            }
        });
    }

    // Pay page: the kept card pays, and the shopper types nothing twice.
    const payPage = document.querySelector('[data-nmi-payment]:not([data-nmi-mode])');
    const kept = sessionStorage.getItem(NMI_KEPT);
    if (payPage && kept) {
        sessionStorage.removeItem(NMI_KEPT);
        document.querySelectorAll('[data-nmi-new-card-only]').forEach((element) => {
            element.hidden = true;
        });
        const notice = document.querySelector('[data-store-nmi-handoff]');
        if (notice) {
            notice.hidden = false;
        }
        nmiSubmit(payPage, JSON.parse(kept));
    }
});
```

What happens: the plugin's own bundle mounts the container on the summary step because it carries
`data-nmi-payment`; *Verify card* tokenises and authenticates the card; `nmi:token` fires with
the token and the 3-D Secure result; *Place order* creates the order and Sylius sends the shopper
to the pay page; the store's script finds the kept fields and calls `submit`, which posts them to
the plugin's endpoint with the page's CSRF token; the plugin charges and completes the payment
exactly as it would for a card typed there. Remove the two hookables and the script, and the
store is back to the plugin's own pay page.
