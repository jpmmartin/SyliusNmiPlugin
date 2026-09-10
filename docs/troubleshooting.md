# Troubleshooting

Every entry here names **the step that was missed**, not the component that appeared to fail. That
is the whole point of the page: almost nothing in this plugin fails loudly, and the symptom you see
is usually several layers away from the cause.

If you are reading this during an install, the fastest thing you can do is re-read the README's
install steps in order and check each one — most of what follows is a step that was skipped.

## Every label of the plugin shows as a dotted key

**What you see:** on the plugin's own admin screens — the gateway-notice grid, the NMI fields on
the payment-method form — every label reads as a key beginning `jpm_martin_sylius_nmi.`, while
Sylius's own labels around them are fine.

**What was missed:** README **step 3** — `bin/console cache:clear` after importing the plugin's
configuration. A Sylius store does not rebuild its translation catalogues when a newly registered
bundle brings translation files: the catalogues cached before the plugin stay in use through every
rebuild of the container, and the plugin's files are read only once the cache is cleared.

**How to confirm:** the files under `var/cache/dev/translations/` are older than the moment you
registered the bundle. After the command they are new, and the labels read as text.

## The payment page has no card fields

**What you see:** the pay page renders its heading, the amount to pay and the three labels —
*Card number*, *Expiry date*, *Security code* — with nothing under them, and a greyed-out *Pay*
button that does nothing. The button is disabled in the markup and enabled by the same script that
fills the fields, so without the script neither happens. No error on the page, nothing in the
browser console, nothing in the log. It looks like a broken template.

**What was missed:** README **step 5**, the front-end build, in one of the two ways it goes wrong
without a sound — or, less often, nothing was missed and something is blocking NMI's script, which
is the third bullet:

- The hook entry on `sylius_shop.base#javascripts` was never added, or names something other than
  the plugin's `@JpmMartinSyliusNmiPlugin/shop/scripts.html.twig`. The usual variant is putting the
  tag in `templates/bundles/SyliusShopBundle/_javascripts.html.twig` instead: **Sylius 2.x does not
  read that file**, so the tag is simply never rendered.
- The tag is rendered but the file it points at is not there — a deployment that shipped the
  manifest and not `public/build`, or a web server that does not serve that directory. The page
  looks exactly the same; the difference is in the browser's Network tab, where `nmi-shop.js`
  fails to load.
- The plugin's script ran, but Collect.js — the NMI script that draws the fields — could not be
  fetched from `https://secure.nmi.com`: a Content Security Policy without that host in
  `script-src`, or a browser extension blocking it. This one is not silent for long: after twenty
  seconds the page says the card form could not be loaded, and the browser's console names the
  blocked request. The README's step 6 lists the hosts a policy has to allow.

**How to confirm:** view the page source and look for a `<script>` whose `src` contains
`nmi-shop`. If it is absent, the hook never rendered the tag. If it is present, open the browser's
Network tab: either the request for it is failing, or it loaded and the request for `Collect.js` is
the one that failed.

The other two ways of getting step 5 wrong do **not** fail silently — they are the next entry.

## The pay page errors, and the error names `entrypoints.json`

**What you see:** the store's error page instead of the pay page. In the log, a
`HookRenderException` for the `nmi` hook in `sylius_shop.base#javascripts`, wrapping one of two
messages.

**What was missed:** README **step 5** again, in one of its two loud ways:

- *Could not find the entrypoints file from Webpack: the file …/public/build/default/entrypoints.json
  does not exist.* Only possible with an overridden template: the third argument to
  `encore_entry_script_tags` was dropped. It is the build name, `app.shop`, and without it Encore
  looks for a build that a Sylius Standard store does not have. The plugin's own template has it.
- *Could not find the entry "nmi-shop" in …/public/build/app/shop/entrypoints.json. Found:
  app-shop-entry.* The entry is not in the shop build's manifest: either `yarn build` was never run
  after adding it, or it was added to the wrong Encore block. A Sylius Standard `webpack.config.js`
  builds four configurations; the entry belongs in the **shop** one, the block that sets
  `public/build/app/shop`.

## NMI is not in the list of gateways when creating a payment method

**What you see:** *Configuration → Payment methods → Create* offers Offline, Stripe, whatever else
you have — and no NMI. It looks as though the package failed to install.

**What was missed:** README **step 2**, registering the bundle — **and step 3 with it**. With the
bundle unregistered but its configuration imported, the store does not start at all: every page and
every console command fails with *Bundle "JpmMartinSyliusNmiPlugin" does not exist or it is not
enabled*, naming the file that imports it. That failure is loud and points at the cause. The silent
one above is what both steps skipped looks like: the package is on disk, Composer is happy, and the
application simply never loads it, so none of its services exist and the gateway never registers
itself.

**How to confirm:** `bin/console debug:container --tag=sylius.gateway_configuration_type` lists
every gateway the form offers, and NMI is not among them. If step 3 was done and step 2 was not,
no console command runs at all — the error above *is* the confirmation.

## The first payment fails on a missing table

**What you see:** the shopper types a card, presses *Pay*, and gets the store's error page. In the
log, a database error naming `jpm_martin_sylius_nmi_transaction`. Opening the pay page itself does
not touch the table; only the charge does.

**What was missed:** README **step 4**, the migration. Run
`bin/console doctrine:migrations:migrate`.

**What has already happened by then:** the card was charged. The gateway is asked first and the
answer recorded second, so the money has moved when the recording fails; the store then rolls its
own side back, and the order stays awaiting payment while NMI holds a sale nothing in the store
knows about. After the migration, find that transaction in NMI's portal and either void it or, if
the shopper is to keep their order, complete the payment by hand from the order page in the admin.

## The shopper never sees the card form — the pay page bounces straight back

**What you see:** the shopper chooses to pay and lands on the order page without ever seeing the
card fields: the pay page answers with a redirect. No error, no flash message, no failed payment.
The payment stays `new`.

**What was missed:** README **step 6** — `SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN` is
pointed at a queue rather than `sync://`. The pay page announces its command and needs the answer
*in the same request*; queued, the command sits in `messenger_messages` and the page has nothing to
render, so it sends the shopper onward.

**How to confirm:** `messenger_messages` gains a row per attempt, and nothing consumes them.

**Note this is not Sylius's default** — Sylius ships `sync://` and it is correct. Something in your
application changed it.

## The pay page says the card could not be authenticated, with a test card

**What you see:** the card fields render and, a few seconds after *Pay*, the page answers *The
card could not be authenticated, so it has not been charged. Try again or use another card.* No
error in the browser console, and the order stays unpaid.

**What was missed:** README *Testing against the sandbox* — the account is not in Test Mode, or
the card is not one of NMI's payer-authentication test cards. The usual cause is the classic
sandbox card, `4111 1111 1111 1111`: it tokenises, but the sandbox answers its 3-D Secure with no
authentication result, and the plugin refuses to charge a card nobody authenticated, exactly as it
would in production.

**How to confirm:** on the same account, `4000 0000 0000 2701` authenticates without a challenge
and pays at once.

## Every charge fails with the generic message, and the log says the key was refused

**What you see:** the card fields render, the card tokenises and passes authentication, and then
the shopper gets *The payment could not be completed. Please try again or use another card* and the
order stays unpaid. In the log, a warning: *The gateway refused the security key of payment method
`nmi`: HTTP 401 from `https://sandbox.nmi.com`…* — naming the host it tried.

**What was missed:** the **Gateway host** on the payment method is not the one that serves this
account. The usual case is an account opened through a reseller with NMI's own host in the field:
NMI's hosts do not know that account's key, so they refuse it. The host is the address you log into
the merchant portal with — enter that, and nothing else changes.

Two rarer causes give the same warning: a key pasted from a different account, or the *query*
security key where the API one belongs.

**How to confirm:** the warning names the host. If it is `secure.nmi.com` or `sandbox.nmi.com` and
your portal is somewhere else, that is the whole diagnosis.

## Webhooks are rejected — everything answers 401

**What you see:** in NMI's portal the deliveries are failing. In your log, repeated
*Rejected an NMI webhook delivery: the signature did not match*.

**What was missed:** the **Webhook signing key** on the payment method does not match the one on
NMI's Webhooks page. Either it was never pasted, it was pasted into the wrong payment method, or it
was rotated at NMI and not here.

**Two things that look identical and are not:** a wrong key and an unknown payment method code both
answer `401` with an empty body. That is deliberate — telling them apart in the response would let
anyone enumerate which of your payment methods take events — so the log is the only place the
difference shows.

**How to confirm:** the log line names the payment method code the request was for. Check that a
method with that code exists, is an NMI one, and carries a signing key.

**You have about three days.** NMI retries a failed delivery for that long and then gives up
silently. Nothing in your store will tell you an event was lost.

## Webhooks never arrive at all — NMI shows failures, your log shows nothing

**What you see:** deliveries failing in NMI's portal, and nothing from the plugin in your
application's log — not even a rejection. In production there is not a single line, because Sylius
excludes 404s from the log; in `dev` there is only Symfony's own *No route found for "POST
/nmi/webhooks/…"*.

**What was missed:** README **step 3** — the application never imported the plugin's *webhook*
routes. There is no endpoint, so the request never reaches the plugin and nothing can log it.

**How to confirm:** `bin/console debug:router | grep nmi_webhook`. If the route is absent, the
import is missing. If it is present, check it has **no prefix** — not the locale, not the admin
path.

## The refund plugin does not offer the NMI method for an order

**What you see:** you installed `sylius/refund-plugin`, an order was paid through NMI, and the
refund plugin's list of refund methods for it does not name the NMI method — only *Offline*, or
nothing.

**What was missed:** nothing to configure — there is no list entry to add, and one a store added
earlier changes nothing. NMI is offered for exactly one method: the one that took the order's
money, when that payment is completed, the method is still enabled, and the plugin has the
gateway's transaction on record. A payment completed some other way — marked paid by hand, paid
through another method — has no NMI transaction behind it and is not offered, because there is
nothing at the gateway to refund against.

**How to confirm:** the order screen shows the payment's transaction, with the gateway's id, when
there is one.

## The refund plugin's refund page fails after the last refund, in a store that overrides its payment-method fragment

**What you see:** the whole of an NMI payment has been given back through the refund plugin, and
its refund page for that order — where it takes you after every refund — fails on
`order.lastPayment('completed').method`, or, with Twig's `strict_variables` off, shows an empty
*original payment method* with nothing selected.

**What was missed:** the refund plugin's own fragment reads the method off the order's last
*completed* payment, which an order whose payment is *refunded* no longer has. The plugin replaces
that fragment with a copy that falls back to the last payment, but leaves a store's own version
alone: one configured for the `payment_method` hookable of
`sylius_refund.admin.order.refund.content.sections.form.fields`, or a file at
`templates/bundles/SyliusRefundPlugin/admin/order/refund/content/sections/form/fields/payment_method.html.twig`.
Give yours the same fallback.

## A refund from the refund plugin's screens was refused

**What you see:** the refund plugin's error message, and above it a sentence naming NMI's reason.
No credit memo, no refund payment, nothing on the order.

**What was missed:** nothing was half done — the refusal undid the credit memo with it. NMI's own
sentence says why: an amount above what the transaction has left (*Refund amount may not exceed
the transaction balance*), a processor that will not refund before settlement — NMI's sandbox
does, a live account may not — or a key the gateway refused. Fix the cause and refund again; for a
transaction that must settle first, the order screen's *Refund* voids the whole amount meanwhile.

## A shopper's saved card is not offered at checkout

**What you see:** the shopper has a card on their saved-cards page, and the pay page does not offer
it.

**Five things it can be**, in the order worth checking:

1. **The card belongs to a different payment method.** A vault reference is scoped to one NMI
   account, so a card saved against one payment method cannot be charged through another. If your
   channel has two NMI methods, this is almost certainly it.
2. **Card saving is off on the method being used.** Turning it off hides cards the store collected
   while it was on — deliberately, so that a switched-off store looks switched off.
3. **The card has expired.** It stays in the account, shown greyed out, and cannot be chosen.
4. **The issuer closed it.** NMI's card updater reported the account behind it was closed, so it is
   no longer offered at all. The shopper's account page says so.
5. **The shopper is not signed in.** Saved cards are never offered to guests.

## The saved-cards page in the shopper's account 404s

**What was missed:** README **step 3** — the account routes were not imported.

**A near miss that looks different:** imported *without* the `/{_locale}/account` prefix, the page
exists — at `/saved-cards` instead of `/en_US/account/saved-cards` — and the account menu links to
it, so a signed-in shopper reaches it and nothing seems wrong. What is wrong is who else can reach
it. The prefix is what puts the page under Sylius's rule requiring a signed-in shopper
(`^/(?!admin|api…)[^/]++/account`, whose first segment is the **locale**); without it, a visitor who
is not signed in is not sent to log in but is handed an error page instead. Put the prefix back.

## *Add a card* answers 404 in the shopper's account

**Not a missed step.** The channel has **more than one** NMI payment method that saves cards, and
the page will not guess which account a new card should belong to. Choosing properly means asking
the shopper before the card form is mounted, and that page does not exist yet.

A channel with exactly one card-saving NMI method does not hit this.

## Credentials read as plain text in the database

**What was missed:** README **step 6** — the encryption key. Your store is almost certainly still
using `config/encryption/test.key`, which `sylius/sylius-standard` ships and points `.env` at in
*every* environment, and which is published in a public skeleton.

The generator refuses to overwrite an existing key, so running it prints *Key generation has been
canceled* and changes nothing — which reads like success. Use `--overwrite`.

**There is a second cause worth knowing:** Sylius only encrypts a gateway configuration that is
*not* a Payum one, and a config created by fixture, migration or API defaults to `usePayum: true`.
The admin form sets it correctly; anything else has to set it itself.

## Nothing above matches

Turn on the log. Almost every refusal this plugin makes writes a line naming what it refused and
why — a rejected webhook, a card that could not be described, a gateway that refused a void. The
symptom you are looking at is usually one line away from its cause in `var/log/`.
