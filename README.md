<h1 align="center">Sylius NMI Plugin</h1>

<p align="center">Card payments through <a href="https://nmi.com">NMI</a> for Sylius 2.2, with the
card details tokenised in the shopper's browser so the store never handles them.</p>

<p align="center"><a href="https://github.com/jpmmartin/SyliusNmiPlugin/actions/workflows/build.yaml"><img src="https://github.com/jpmmartin/SyliusNmiPlugin/actions/workflows/build.yaml/badge.svg?branch=main" alt="Build — the test suite on main, in both supported configurations"></a> <a href="https://github.com/jpmmartin/SyliusNmiPlugin/actions/workflows/install.yaml?query=event%3Arelease"><img src="https://github.com/jpmmartin/SyliusNmiPlugin/actions/workflows/install.yaml/badge.svg?event=release" alt="Install — the last published version, installed from its own README into a store that had never seen it"></a></p>

---

## What it does

- **Card payments** on Sylius's own pay page, the one shown right after *Place order*, collected
  by NMI's browser component. The card number, expiry and verification value are entered inside
  the gateway's frames; the only thing the store ever receives is a single-use token.
- **3-D Secure** on every payment, in the browser. A frictionless authentication shows the shopper
  nothing extra; a challenge is presented in place.
- **Charge now, or authorise and capture later**, chosen per payment method.
- **Capture** by hand from the order screen or automatically when a shipment goes out.
- **Void and refund** from the order screen, with the plugin choosing between them.
- **Saved cards**, off until you turn them on. A signed-in shopper can keep a card at NMI and pay
  with it next time; the store holds no card number, only the reference NMI gives back.
- **The same flow headless**, through the shop API Sylius already documents. No endpoint of this
  plugin is required.
- **Per payment method credentials**, so two channels can charge two different NMI accounts.

## Requirements

| | |
|---|---|
| PHP | 8.2 or newer |
| Sylius | 2.2 or newer |
| Database | MySQL, MariaDB or PostgreSQL. One migration serves all three; every build checks the schema on PostgreSQL and on MySQL |

Sylius 1.x is not supported and will not be: this plugin is built on the `PaymentRequest` model
introduced in 2.x and does not use Payum.

## Installation

### 1. Require the package

```bash
composer require jpmmartin/sylius-nmi-plugin
```

Nothing else works until this is done, and Composer will say so plainly. It is the only step in
this list that fails loudly.

### 2. Register the bundle

```php
# config/bundles.php

return [
    // ...
    JpmMartin\SyliusNmiPlugin\JpmMartinSyliusNmiPlugin::class => ['all' => true],
];
```

Skip this and one of two things happens. With step 3 done, the store does not start: every page and
every console command fails with *Bundle "JpmMartinSyliusNmiPlugin" does not exist or it is not
enabled*, which at least names the cause. With step 3 skipped as well, the plugin is installed but
not loaded — none of its services exist, and **NMI never appears in the list of gateways** when you
go to create a payment method, which looks like the package failed to install.

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

# Required if you want NMI to tell your store what it did. Without it there is no endpoint and
# nothing arrives. No prefix: not the locale, not the admin path. See "Webhooks" below.
jpm_martin_sylius_nmi_webhook:
    resource: "@JpmMartinSyliusNmiPlugin/config/routes/webhook.yaml"

# Required if you turn saved cards on, and harmless if you do not. See "Saved cards" below.
jpm_martin_sylius_nmi_shop_account:
    resource: "@JpmMartinSyliusNmiPlugin/config/routes/shop_account.yaml"
    prefix: /{_locale}/account
    requirements:
        _locale: ^[A-Za-z]{2,4}(_([A-Za-z]{4}|[0-9]{3}))?(_([A-Za-z]{2}|[0-9]{3}))?$
```

The shop route receives the token the browser produces. The admin route adds the *Void* action to
the payment row, which Sylius itself does not ship. The account route is the shopper's saved-cards
page. The webhook route is where NMI delivers what it did outside your store.

**Skip this step and the plugin is loaded but unreachable**: the pay page has nowhere to post the
card token, the *Void* action is missing from the payment row, the saved-cards page 404s, and NMI
has no endpoint to deliver to — so nothing it does outside your store ever reaches it.

**Neither prefix is cosmetic.** Sylius's admin firewall is defined by the admin path, so importing
the admin routes without it leaves the void action reachable by anyone who knows the URL. The
account rule is `^/(?!admin|api…)[^/]++/account`, and that first segment is the **locale** — mount
the account routes without it and the pages sit inside the shop firewall but outside the rule that
requires a signed-in shopper.

Then clear the cache:

```bash
bin/console cache:clear
```

A Sylius store does not rebuild its translation catalogues when a newly registered bundle brings
translation files, so until you do this every label of the plugin shows as its key.

### 4. Run the migration

The plugin adds four tables: one recording every transaction it makes at the gateway, one for the
cards shoppers save, one recording the webhook deliveries it has accepted, and one for what the
gateway reports that nobody in your store caused. All four are created whether or not you turn
saved cards or webhooks on, and stay empty until you do.

```bash
bin/console doctrine:migrations:migrate
```

One migration, and it runs on whichever engine your store uses — MySQL, MariaDB or PostgreSQL.
It is written against Doctrine's schema representation rather than an engine's SQL, so Doctrine
derives the statements for your engine when it runs, and nothing is skipped.

Skip this and the store works right up until the first payment, which fails on a missing table —
in the middle of checkout, with a shopper watching, and **after their card has been charged**: the
gateway is asked before the answer is recorded, so the money moves and the order stays unpaid.

### 5. Build the front-end assets

The card fields are drawn by NMI's Collect.js, which the plugin's script fetches from NMI when the
pay page loads — there is nothing to install for them. The authentication that follows uses NMI's
browser component, which NMI distributes as an npm package rather than a script tag, so that one
has to be bundled with your store's assets.

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

The script tag comes with the plugin: point the shop's `javascripts` hook at the template it ships
and there is no file to create. **Do not create
`templates/bundles/SyliusShopBundle/_javascripts.html.twig`** — Sylius 2.x does not read it, so the
page renders with no card fields and nothing to explain why. Add the entry and never run
`yarn build`, and the page errors instead, naming `entrypoints.json`.

If your store already has this file with a `sylius_twig_hooks:` key, add the `hooks:` entry to it
rather than pasting a second one — two of the same key at the top level and the file stops parsing.

```yaml
# config/packages/twig_hooks.yaml
sylius_twig_hooks:
    hooks:
        'sylius_shop.base#javascripts':
            nmi:
                template: '@JpmMartinSyliusNmiPlugin/shop/scripts.html.twig'
                priority: -10
```

That template assumes the two names above — the `nmi-shop` entry, in the `app.shop` build a Sylius
Standard store compiles its shop assets under. If your store builds under other names, override it
at `templates/bundles/JpmMartinSyliusNmiPlugin/shop/scripts.html.twig` and change them there.

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
environment, not only `test`. That key is published in a public skeleton, so credentials encrypted
with it are not encrypted against anyone who knows that. Make your own now, before you create the
payment method: the key in use when a method is saved is the key its credentials are bound to, and
changing it afterwards makes them unreadable until you re-enter them.

A key of your own must stay out of the repository, and the skeleton does not arrange that: its
`.gitignore` excludes `config/jwt/*.pem` but nothing under `config/encryption/`. So, in this order
— the generator writes wherever the store points *at the moment it runs*:

```gitignore
# .gitignore — the rule the skeleton already uses for its JWT keys; test.key stays tracked, .env names it
/config/encryption/*.key
!/config/encryption/test.key
```

```dotenv
# .env.local — a sibling of the skeleton's key: writable by the web user, ignored by git
SYLIUS_PAYMENT_ENCRYPTION_KEY_PATH=%kernel.project_dir%/config/encryption/payment.key
```

```bash
bin/console sylius:payment:generate-key
```

The command writes the key where `.env.local` now points and touches nothing else. It needs no
`--overwrite`, because the new path is empty. Run with that flag *before* the path was changed, it
would have replaced the skeleton's `test.key` with your key instead; if that already happened, copy
that file to `config/encryption/payment.key` rather than generating again, and the credentials you
have stored stay readable. Keep the file across deployments the way you keep the JWT keys; how is
your deployment's business.

**If your store sends a Content Security Policy**, the card fields and the authentication both run
in frames served by NMI, and the script that draws the fields is fetched from NMI too, along with a
stylesheet of its own. Allow `https://secure.nmi.com` in `script-src`, `style-src`, `connect-src`
and `frame-src`, and `https://secure.networkmerchants.com` in `script-src`, `connect-src` and
`frame-src`. Collect.js also asks for Apple's Pay SDK from `https://applepay.cdn-apple.com`;
blocking that one costs nothing, since this plugin offers no wallet. These hosts are compiled into
NMI's scripts and cannot be changed, including on a reseller account.

## Configuring a payment method

*Configuration → Payment methods → Create → NMI*, then fill in the form:

| Field | What it is |
|---|---|
| **Tokenization key** | The public key. It reaches the shopper's browser by design and cannot charge anything |
| **Security key** | The private API key. It never leaves your server, and is stored encrypted |
| **Gateway host** | The address of the gateway that serves this account: `https://secure.nmi.com` for an NMI live account, `https://sandbox.nmi.com` for an NMI sandbox account, or your reseller's host. Stored encrypted with the keys |
| **Authorize first, capture later** | Off: checkout charges the card. On: checkout only reserves the money |
| **Let shoppers save their card** | Off by default. See *Saved cards* below |
| **Authenticate saved cards with 3-D Secure** | On by default, and only meaningful once the setting above is on |

Both keys come from the NMI merchant portal, under *Settings → Security Keys*. The gateway host is
the address in your browser's bar while you are there: `secure.nmi.com` for an account NMI hosts,
your reseller's own name otherwise. Card details are tokenised in the browser against NMI's hosts
whatever you enter here; the host is where *your store's* calls go.

Credentials belong to the payment method, so a store with several channels can give each one its
own NMI account.

The card is typed after *Place order*, on the pay page Sylius shows for the order — the same place
Sylius's own payment flow and the Stripe plugin put it; the checkout's *Payment* step only chooses
the method, and shows no card fields for any gateway. A store that wants the card asked for on the
checkout's summary step instead, the way the Adyen plugin does, has a recipe for it in
[Extending](docs/extending.md).

Before the first payment on a sandbox account, read [Testing against the
sandbox](#testing-against-the-sandbox) below: the account has to be in Test Mode, and the card
you type decides whether authentication passes — NMI's classic test card does not pass it.

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

**With `sylius/refund-plugin`**, which Sylius Standard ships, NMI is offered on that plugin's own
refund screens too, and there is nothing to configure: an order paid with an NMI method is offered
that method — the one that took the money, never another NMI account — as soon as the payment is
complete. A refund made there, for part of the order or all of it, is sent to NMI and the refund
payment is completed only when NMI approves it; a refusal undoes the credit memo and tells you
NMI's reason. Several partial refunds of one payment are each their own refund at NMI, which keeps
the balance and refuses one that would exceed it. NMI's reference says a transaction has to settle
before it can be refunded, while its sandbox refunds unsettled ones without complaint; the plugin
asks either way and shows you the answer, and the order screen's *Refund* — a void, before
settlement — remains the way to give the whole amount back if NMI says no. The refund plugin's own
*Complete* button never applies to an NMI refund: money the gateway has not returned is not marked
returned. Note that the refund plugin hides Sylius's own *Refund* button on the order screen, so
with it installed its screens are where every refund happens; partial refunds that add up to the
whole payment mark it refunded just as one full refund would, and its refund page — where it takes
you after each refund — still opens for that order afterwards, as the record of what went back.
Without the refund plugin, the order screen's *Refund* gives back whatever has not been returned
yet — a refund made in NMI's portal and reported by webhook is subtracted first — and refuses when
nothing is left.

## Testing against the sandbox

An NMI sandbox account is served from `https://sandbox.nmi.com`, which is what its payment
method's gateway host must say. A reseller account has no separate sandbox host: it is the same
host as always, and *Test Mode* in the merchant portal is the switch.

Put the account into Test Mode in the merchant portal first. Then the card decides what happens:
every new card is authenticated with 3-D Secure, and on the sandbox only the cards NMI publishes
under [Testing Values for Payer Authentication](https://docs.nmi.com/docs/testing) come back with
an authentication result. Two of them show the two outcomes a shopper can meet:

| Card | What happens |
|---|---|
| `4000 0000 0000 2701` | Authenticates without a challenge: the payment goes through with no extra screen |
| `4000 0000 0000 2503` | Authenticates through a challenge: a dialog opens over the pay page, the sandbox's mock issuer shows the one-time code to type, and the payment goes through once it is entered |

NMI's page names no expiry date and no verification value for them. On the sandbox on
2026-09-10, a future expiry and an arbitrary three-digit value were accepted — observed there,
not promised by NMI. The page lists nine more cards, one for each failure and error, and is the
place to read them rather than a copy here that would drift.

The classic sandbox card, `4111 1111 1111 1111`, is not in that set. With it the authentication
widget completes without an authentication result, and the pay page says the card could not be
authenticated and has not been charged: the plugin refusing to charge a card nobody authenticated,
not a fault. See *Troubleshooting*.

Amounts under `1.00` are declined on purpose, which is the quickest way to see the decline path.

## Saved cards

Off until you turn it on, and *off* means absent rather than dormant: no option on the pay page, no
entry in the account menu, no page that answers, and no row ever written. A store that leaves it
alone behaves exactly as it did before this feature existed.

Turn on **Let shoppers save their card** on the payment method, and import the account routes shown
in step 3. **Import them before you turn the setting on**: the account menu links to those routes,
and a menu whose route does not exist takes the whole account area down with it. With the setting
off the entry is not added at all, which is why leaving the import out costs a store nothing until
it opts in. Then:

- A **signed-in** shopper is offered *Save this card for next time* on the pay page. Nothing is
  saved unless they tick it, and a guest is never offered it at all.
- Their saved cards appear at *My account → Saved cards*, where they can add one, choose which is
  the default, and remove one.
- At checkout their saved cards are offered with the default already chosen, so paying is a
  choice rather than a retype.

**The store never holds a card number.** What it keeps is the reference NMI gives back, encrypted
at rest, plus the four digits, brand and expiry a receipt already prints. Removing a card asks the
gateway to forget it before the row goes, and deleting a customer forgets theirs too.

Cards belong to the payment method they were saved under, because a reference means nothing to any
NMI account but the one that issued it. A shopper with cards on two of your channels sees each
card only where it can be charged.

**One caveat if a channel has more than one NMI method that saves cards.** Adding a card *from the
account area* charges nothing, so there is no order to say which account it belongs to — and the
browser mints the token with a particular account's tokenization key, so the choice has to be made
before the card form appears. Rather than file the card against an account nobody picked, the
add-a-card page answers 404 in that configuration. Saving a card *while paying* is unaffected: the
payment names the account. If you need both, give each NMI account its own channel.

### Authenticating a saved card

**Authenticate saved cards with 3-D Secure** is on unless you turn it off, and the default is the
answer to a question rather than an accident. Turn it off and paying with a saved card is one
click. For cardholders in the EU and the UK that has consequences: the issuer may decline a payment
that carries no authentication, and liability for a chargeback stays with you rather than moving to
the issuer. Selling into North America that pressure does not apply the same way, and the one-click
payment is the more common trade. The form says the same thing, so nobody has to read this first.

> This paragraph and the form's help text make claims about issuer behaviour and chargeback
> liability in two jurisdictions. They have not been reviewed by anyone qualified to make them.
> Treat them as a prompt to check your own position, not as advice.

## Webhooks

Everything NMI does outside your store is invisible to it until you wire this up: a refund issued
from NMI's own portal, a batch that failed to settle, a chargeback, a card your shopper's bank
reissued or closed. With webhooks configured, all of it reaches the order it belongs to.

**Without the route import there is no endpoint and nothing arrives.** The plugin cannot import it
for you. It is the `jpm_martin_sylius_nmi_webhook` block in step 3, and it takes **no prefix** —
not the locale, because NMI's URL cannot depend on a language it knows nothing about, and not the
admin path, because that is behind a firewall NMI cannot pass.

### 1. Give NMI the URL

One endpoint takes every category. The last segment is the **code of the payment method**, which is
what tells the store which NMI account the delivery belongs to when you have more than one:

```
https://your-store.example/nmi/webhooks/<payment method code>
```

It must be `https` with a valid certificate; NMI refuses anything else.

### 2. Create the webhook at NMI

In the Merchant Portal, **Settings → Webhooks → Create**. Paste the URL and subscribe these
categories:

| Category | What it gives you |
|---|---|
| **Transactions** | Refunds and voids performed in NMI's portal land on the order |
| **Settlement** | The store learns a transaction has settled, and stops asking NMI whether a reversal must be a refund |
| **Chargebacks** | Money taken back is recorded and shown to you |
| **Automatic Card Updater** | A saved card the issuer renewed, closed, or flagged is updated |

Subscribe the `success`, `failure` and `unknown` variants of the transaction events you use. There
is no harm in subscribing more than the plugin acts on: anything it does not recognise is accepted,
logged and ignored.

### 3. Paste the signing key

The same Webhooks page shows a **signing key** for the account. Put it in the payment method's
*Webhook signing key* field in Sylius. It is stored encrypted and never reaches the browser.

**Until you paste it, the endpoint refuses everything** — there is nothing to verify a delivery
against, and accepting unverified instructions about money is not a thing this plugin will do. That
is also what keeps a store that never wires webhooks behaving exactly as it did before.

If you rotate the key at NMI, change it here in the same sitting. Every delivery fails verification
in the meantime, NMI gives up after three days, and the only visible sign is an error in your log.

**Optionally, restrict the endpoint to NMI's addresses** at your web server or firewall. NMI
delivers from `104.192.32.81`–`104.192.32.87` and `104.192.36.81`–`104.192.36.87`. Treat this as a
second lock, never as a substitute for the signing key.

### 4. Prune the received events on a schedule

Every accepted delivery is written down, and that record is what makes NMI's retries harmless — the
same event delivered twice changes state once. It has to be bounded, so run this daily:

```bash
bin/console jpm-martin:sylius-nmi:prune-received-events
```

It keeps **30 days** by default. That number is not arbitrary and is not a preference: **NMI retries
a delivery for three days**, so anything shorter than four risks deleting the record of an event
still being retried — which would then be applied a second time. Thirty is that window with an order
of magnitude of margin, and short enough that the table does not become a permanent archive of
payloads carrying billing addresses and cardholder emails. Pass `--days` to change it; the command
refuses a period inside the retry window unless you also pass `--force`.

Nothing you need to keep lives there. Chargebacks and failed settlements are recorded separately and
are never pruned.

### What you will see

**Sales → Gateway notices** lists what NMI reported that nobody in your store caused: chargebacks
and batches that failed to settle. There is no setting to hide a chargeback, and the page is empty
until something happens.

Two settings on the payment method are off by default and stay off unless you say otherwise:

- **Email the shopper when a saved card is closed or flagged.** Everything else happens either way —
  the card is marked, the account shows it, the checkout stops offering it. Only the email is
  skipped.
- **Tell me about transactions this store does not recognise.** Useful only if the NMI account is
  yours alone. If you share it with another shop or another system, every one of their transactions
  arrives here too and this would list all of them.

## Limitations

One thing this release deliberately does not do, and one thing it no longer asks of you. They are
stated here rather than discovered.

**No partial captures.** An order shipped in several parcels is charged in full at the first
shipment. This is not only a scoping decision: the gateway closes an authorisation on the first
capture, so a partial capture would forfeit the rest rather than leave it claimable.

**The refund plugin needs no entry for NMI.** There is nothing to add to its
`sylius_refund.supported_gateways` list: NMI is offered for the order's own method without it, and
an entry a store added earlier changes nothing. Refunds made there are real refunds at NMI; see
*Voiding and refunding* above for what NMI accepts before a transaction settles.

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

NMI licenses its gateway to resellers who run it under their own host name. If yours does, the
**Gateway host** on the payment method is that name — the address you log into the merchant
portal with — and nothing else changes. There is no store-wide setting and nothing to put in a
configuration file: the host belongs to the account exactly like the keys, so a store with two
accounts on two resellers gives each payment method its own.

Get it wrong and the symptom is precise: cards tokenise and authenticate, every charge fails with
the generic message, and the log carries a warning that the gateway refused the security key at
the host you entered. See *Troubleshooting*.

Note that **the browser component always tokenises against NMI's hosts**, whatever the field says.
Your tokenization key identifies your merchant account to them, so this works — but it is the
gateway's behaviour, not something the plugin chooses.

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

**Saved cards, headlessly.** With the setting on and the shopper signed in, step 1 also answers
with `can_store_card: true`, the cards they already have, and whether paying with one authenticates
again. Both keys are **absent** rather than false when they do not apply, so a store that never
turned saved cards on sees exactly the response shown above.

```json
{
    "can_store_card": true,
    "authenticate_stored_cards": true,
    "stored_cards": [
        { "id": 42, "brand": "Visa", "last_four": "1111", "expiry_month": 10, "expiry_year": 2030, "is_default": true, "is_expired": false }
    ]
}
```

`id` names the card in this store and nowhere else; the gateway's own reference stays on the
server. In step 2, add `"store_card": "1"` to keep the card being paid with, or send
`"stored_card": "42"` **instead of** `payment_token` to pay with one already saved. An expired card,
another shopper's, or one saved under a different payment method is refused there rather than
charged.

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

**A saved card's reference is fetched, not rendered.** With authentication on, the browser needs
the gateway's own reference in order to authenticate a saved card — 3-D Secure runs in the browser
and has no server-side form. The page does not carry it: it is fetched when the shopper presses
Pay, from a route that answers only the owner of that card, so the reference appears in no page
source, cached copy or screenshot. It cannot charge anything on its own; charging still needs the
security key, which never leaves your server.

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

## Further reading

This README carries the whole happy path — you should never *need* the pages below to get a store
taking payments. They are for depth.

| | |
|---|---|
| [Configuration reference](docs/configuration.md) | Every field on the payment method form: what it is, where to get it, and what each switch costs |
| [Troubleshooting](docs/troubleshooting.md) | Symptom to missed step. Almost nothing here fails loudly, so this is the page to reach for |
| [Extending](docs/extending.md) | Every seam a store may rely on — hooks, decorable services, routes, the browser contract — and the rule that everything else is internal |
| [Upgrading](docs/upgrading.md) | What a version number promises, what to do on each kind of release, and where to report a problem |

The documentation is English only. The plugin's own interface is bilingual — different audiences,
and prose drifts faster than string catalogues.

## Versioning and changes

Released under [Semantic Versioning](https://semver.org/spec/v2.0.0.html): a caret constraint on
this package is safe, and anything that would break an existing store arrives only in a major
release with a written migration note. What changed in each release is in
[CHANGELOG.md](CHANGELOG.md); what to do about it, in [docs/upgrading.md](docs/upgrading.md); how a
release is cut, and what counts as breaking, in [RELEASING.md](RELEASING.md).

## Licence

MIT. See [LICENSE](LICENSE).
