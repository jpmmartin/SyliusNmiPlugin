<h1 align="center">Sylius NMI Plugin</h1>

<p align="center">Card payments through <a href="https://nmi.com">NMI</a> for Sylius 2.2, with the
card details tokenised in the shopper's browser so the store never handles them.</p>

---

## What it does

- **Card payments** on Sylius's own pay page, collected by NMI's browser component. The card
  number, expiry and verification value are entered inside the gateway's frames; the only thing
  the store ever receives is a single-use token.
- **3-D Secure** on every payment, in the browser. A frictionless authentication shows the shopper
  nothing extra; a challenge is presented in place.
- **Charge now, or authorise and capture later**, chosen per payment method.
- **Capture** by hand from the order screen or automatically when a shipment goes out.
- **Void and refund** from the order screen, with the plugin choosing between them.
- **The same flow headless**, through the shop API Sylius already documents. No endpoint of this
  plugin is required.
- **Per payment method credentials**, so two channels can charge two different NMI accounts.

## Requirements

| | |
|---|---|
| PHP | 8.2 or newer |
| Sylius | 2.2 or newer |
| Database | MySQL or PostgreSQL |

Sylius 1.x is not supported and will not be: this plugin is built on the `PaymentRequest` model
introduced in 2.x and does not use Payum.

## Installation

### 1. Require the package

```bash
composer require jpmmartin/sylius-nmi-plugin
```

### 2. Register the bundle

```php
# config/bundles.php

return [
    // ...
    JpmMartin\SyliusNmiPlugin\JpmMartinSyliusNmiPlugin::class => ['all' => true],
];
```

### 3. Import its configuration and routes

```yaml
# config/packages/jpm_martin_sylius_nmi.yaml
imports:
    - { resource: "@JpmMartinSyliusNmiPlugin/config/config.yaml" }
```

```yaml
# config/routes/jpm_martin_sylius_nmi.yaml
jpm_martin_sylius_nmi_shop:
    resource: "@JpmMartinSyliusNmiPlugin/config/routes/shop.yaml"

jpm_martin_sylius_nmi_admin:
    resource: "@JpmMartinSyliusNmiPlugin/config/routes/admin.yaml"
    prefix: /%sylius_admin.path_name%
```

The shop route receives the token the browser produces. The admin route adds the *Void* action to
the payment row, which Sylius itself does not ship.

**The admin prefix is not cosmetic.** Sylius's admin firewall is defined by that path, so importing
the admin routes without it leaves the void action reachable by anyone who knows the URL.

### 4. Run the migration

The plugin adds one table, which records every transaction it makes at the gateway.

```bash
bin/console doctrine:migrations:migrate
```

Migrations ship for MySQL and for PostgreSQL; each skips itself on the other engine.

### 5. Build the front-end assets

NMI distributes its browser component as an npm package rather than a script tag, so it has to be
bundled with your store's assets.

```bash
yarn add @nmipayments/nmi-pay
```

A Sylius Standard `webpack.config.js` builds **four** Encore configurations, not one. The entry goes
in the shop block — the one that sets `public/build/app/shop` — before its `Encore.getWebpackConfig()`:

```js
// webpack.config.js, inside the shop block
Encore
    .addEntry('app-shop-entry', './assets/shop/entrypoint.js')
    .addEntry('nmi-shop', './vendor/jpmmartin/sylius-nmi-plugin/assets/shop/entrypoint.js')
;
```

The script tag goes on the shop's `javascripts` hook. **Do not create
`templates/bundles/SyliusShopBundle/_javascripts.html.twig`** — Sylius 2.x does not read it, so the
page renders with no card fields and nothing to explain why.

```yaml
# config/packages/twig_hooks.yaml
sylius_twig_hooks:
    hooks:
        'sylius_shop.base#javascripts':
            nmi:
                template: 'shop/nmi_scripts.html.twig'
                priority: -10
```

```twig
{# templates/shop/nmi_scripts.html.twig #}
{{ encore_entry_script_tags('nmi-shop', null, 'app.shop') }}
```

The third argument is the build name and is required: that store's shop assets are built under
`app.shop`, and without it Encore looks in a manifest that does not contain this entry.

```bash
yarn build
bin/console assets:install
```

Without this step the pay page renders but no card fields appear, which looks like a broken page
rather than a missing build.

### 6. Check two things about your application

**Payment requests must be handled synchronously.** The pay page announces its command and asks
for a response inside the same HTTP request, so a queued command leaves the page with nothing to
render — and it fails quietly: the shopper is redirected away with no error anywhere. Sylius's own
default is correct; only change it if you know why.

```dotenv
# .env — this is Sylius's default. Do not point it at a queue.
SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN=sync://
```

**Gateway credentials are encrypted at rest**, by Sylius rather than by this plugin, and that needs
a key — **which your store almost certainly already has, and it is the wrong one.**
`sylius/sylius-standard` ships `config/encryption/test.key` and points `.env` at it in *every*
environment, not only `test`. The generator refuses to overwrite an existing key, so running it
prints *Key generation has been canceled* and changes nothing, which reads like success.

That key is published in a public skeleton. Credentials encrypted with it are not encrypted against
anyone who knows that. Before going live, make your own and keep it out of the repository:

```bash
bin/console sylius:payment:generate-key --overwrite
```

```dotenv
# .env.local — point at a key you generated, not the one the skeleton shipped
SYLIUS_PAYMENT_ENCRYPTION_KEY_PATH=/etc/sylius/payment.key
```

Changing the key after credentials are stored makes the stored ones unreadable, so do this before
you create the payment method — or re-enter the credentials afterwards.

**If your store sends a Content Security Policy**, the card fields and the authentication both run
in frames served by NMI. Allow `https://secure.nmi.com` and `https://secure.networkmerchants.com`
in `script-src`, `connect-src` and `frame-src`. These hosts are compiled into NMI's package and
cannot be changed, including on a reseller account.

## Configuring a payment method

*Configuration → Payment methods → Create → NMI*, then fill in four fields:

| Field | What it is |
|---|---|
| **Tokenization key** | The public key. It reaches the shopper's browser by design and cannot charge anything |
| **Security key** | The private API key. It never leaves your server, and is stored encrypted |
| **Environment** | *Sandbox* or *Production*. This selects the gateway host; NMI runs a separate sandbox server |
| **Authorize first, capture later** | Off: checkout charges the card. On: checkout only reserves the money |

Both keys come from the NMI merchant portal, under *Settings → Security Keys*.

Credentials belong to the payment method, so a store with several channels can give each one its
own NMI account.

### Authorise-then-capture

With the flag on, checkout leaves the payment **authorized** and the money reserved but not taken.
It is claimed when either happens:

- an operator presses **Complete** on the order screen, or
- a shipment for the order is marked as shipped.

An order shipped in several parcels is captured once. If the gateway refuses a capture, the payment
is left authorised and the reason is recorded on it, where the order screen shows it.

### Voiding and refunding

*Void* cancels a transaction the gateway has not settled. *Refund* gives the money back, and the
plugin decides how: it tries a void first, because a void never appears on the cardholder's
statement, and falls back to a refund when the gateway says the transaction has settled. You do not
have to know which applies — nothing the gateway exposes would tell you.

## Limitations

Three things this release deliberately does not do. They are stated here rather than discovered.

**No webhooks.** Anything done inside NMI's own portal — a refund issued there, a chargeback, a
card the issuer replaced — is invisible to the store. The store's record and the gateway's can
drift apart, and only actions taken through Sylius keep them together.

**No partial captures.** An order shipped in several parcels is charged in full at the first
shipment. This is not only a scoping decision: the gateway closes an authorisation on the first
capture, so a partial capture would forfeit the rest rather than leave it claimable.

**The optional refund plugin needs one line.** `sylius/refund-plugin` keeps its own list of
gateways it will refund through — `offline` alone by default — and a gateway missing from it is
not refused, it simply never appears. Add this gateway to it:

```yaml
# config/services.yaml
parameters:
    sylius_refund.supported_gateways:
        - offline
        - nmi
```

Miss that entry and NMI is absent from the refund plugin's screens with no error anywhere, so this
plugin puts a notice on the NMI payment method form when it finds the two installed and not
talking to each other.

Installing the refund plugin itself is more than the package: it needs **three** bundles
registered, its configuration and **its routes** imported, and its migrations run.

```php
// config/bundles.php
Knp\Bundle\SnappyBundle\KnpSnappyBundle::class => ['all' => true],
Sylius\PdfGenerationBundle\SyliusPdfGenerationBundle::class => ['all' => true],
Sylius\RefundPlugin\SyliusRefundPlugin::class => ['all' => true],
```

The routes are not optional. That plugin adds a credit-memo link to the admin sidebar, which every
admin page renders, so a store that registers the bundle and skips its routes gets HTTP 500 on the
whole admin rather than a missing menu entry.

None of this is required by this plugin. Refunding from the order screen works without the package
at all, and the plugin's own suite is run in both configurations for exactly that reason.

## Reseller and white-label gateways

NMI licenses its gateway to resellers who run it under their own host name. If yours does, set that
host once for the whole store:

```yaml
# config/packages/jpm_martin_sylius_nmi.yaml
jpm_martin_sylius_nmi:
    api_base_url: 'https://gateway.example.com'
```

Leave it unset and each payment method's *Environment* selects NMI's own host.

Note that **the browser component always tokenises against NMI's hosts**, whatever this is set to.
Your tokenization key identifies your merchant account to them, so this works — but it is the
gateway's behaviour, not something the plugin chooses.

## Testing against the sandbox

Put the account into Test Mode in the merchant portal, then use NMI's published test cards. Their
3-D Secure test cards are the set documented under *Testing Values for Payer Authentication* —
the ones beginning `4000 0000 0000 27…`, which is a different set from the one on their
sandbox page, and the only one this component answers with real authentication values.

Amounts under `1.00` are declined on purpose, which is the quickest way to see the decline path.

## Headless

The same payment, driven through the shop API Sylius already documents. **This plugin adds no
endpoint a headless client has to call** — the two operations below are the platform's own, and the
plugin's test suite asserts that these are the only routes the flow matches.

**1. Create the payment request.** The response carries everything needed to tokenise a card, so
there is no second round trip before the card form.

```http
POST /api/v2/shop/orders/{orderTokenValue}/payment-requests
Content-Type: application/ld+json

{ "paymentId": 1564, "paymentMethodCode": "nmi_card" }
```

```json
{
    "hash": "01a06d18-5790-78dc-a117-6c0489f14828",
    "state": "processing",
    "action": "capture",
    "responseData": {
        "tokenization_key": "tok-public-0123",
        "amount": 1299,
        "currency_code": "USD",
        "amount_major": "12.99",
        "action": "capture",
        "first_name": "Ada",
        "last_name": "Lovelace",
        "address1": "12 Marylebone Rd",
        "city": "London",
        "postal_code": "NW1 5JR",
        "country": "GB"
    }
}
```

`amount` is in the currency's smallest unit and `amount_major` is the decimal form NMI's
authentication call expects. The cardholder fields are what the issuer is told when deciding
whether to challenge; send them on, or leave them out and expect more challenges.

**2. Return the token.** Tokenise with NMI's own component or API using `tokenization_key`, do 3-D
Secure if you want it, then send the result back. `payment_token` is the only required key.

```http
PUT /api/v2/shop/payment-requests/{hash}
Content-Type: application/ld+json

{
    "payload": {
        "payment_token": "00000000-000000-000000-000000000000",
        "cardholder_auth": "verified",
        "cavv": "AAABBBBBBBBBBBBBBBBBBBBBBBBB",
        "eci": "05",
        "three_ds_version": "2.2.0",
        "directory_server_id": "3f6fb1f8-f719-46c9-905b-bab446f4de30"
    }
}
```

The response comes back with `state` `completed` and the gateway's transaction id in
`responseData`. A declined card comes back `failed`, with `message_key` and the issuer's wording in
`detail`, and the order stays payable so another card can be tried. `GET
/api/v2/shop/payment-requests/{hash}` reads the same thing back later.

The order must be placed — the platform refuses a payment request for an order whose checkout is
not complete.

## What the pay page carries

Two things worth knowing before you go live.

**The pay URL is the capability.** Sylius looks a payment request up by its hash and renders the
page with no ownership check — that is the platform's model, the same as an order token. Whoever
holds the link can complete the payment, and can also read what the page carries for
authentication: the name, email, street, city, postcode, province and phone from the billing
address. Those travel because the issuer decides whether to challenge the shopper from what it is
told about them, and the gateway is explicit that the more it receives the fewer challenges there
are. If you would rather send less, override
`@JpmMartinSyliusNmiPlugin/shop/pay/nmi/card_form.html.twig` and drop the fields you do not want;
expect more challenges.

**Credentials are encrypted by Sylius, not by this plugin — and only when the gateway config says
it is not a Payum one.** A fresh `GatewayConfig` has `usePayum = true`, and Sylius encrypts only
when that is false. The admin form turns it off for any factory Payum does not know, which is why
credentials entered there are unreadable in the database.

Create payment methods **by fixture, migration or API** and the flag is yours to set:

```php
$gatewayConfig->setUsePayum(false);
```

Leave it out and the security key is stored in plain text, with no error and nothing in the admin
to show it — and the method is also classed as a Payum gateway, which is not what it is.

## Versioning and changes

Released under [Semantic Versioning](https://semver.org/spec/v2.0.0.html): a caret constraint on
this package is safe, and anything that would break an existing store arrives only in a major
release with a written migration note. What changed in each release is in
[CHANGELOG.md](CHANGELOG.md); how a release is cut, and what counts as breaking, in
[RELEASING.md](RELEASING.md).

## Licence

MIT. See [LICENSE](LICENSE).
