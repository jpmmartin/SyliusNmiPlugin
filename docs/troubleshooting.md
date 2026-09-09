# Troubleshooting

Every entry here names **the step that was missed**, not the component that appeared to fail. That
is the whole point of the page: almost nothing in this plugin fails loudly, and the symptom you see
is usually several layers away from the cause.

If you are reading this during an install, the fastest thing you can do is re-read the README's
install steps in order and check each one — most of what follows is a step that was skipped.

## The payment page has no card fields

**What you see:** the pay page renders, the *Pay* button is there, and where the card number should
be there is nothing. No error on the page, nothing in the browser console, nothing in the log. It
looks like a broken template.

**What was missed:** README **step 5**, the front-end build — or one of the three ways it goes
wrong on its own:

- The entry was added to the wrong Encore block. A Sylius Standard `webpack.config.js` builds four
  configurations; the entry belongs in the **shop** one, the block that sets
  `public/build/app/shop`.
- `yarn build` was never run after adding it, or `bin/console assets:install` was not run after
  that.
- The script tag was put in `templates/bundles/SyliusShopBundle/_javascripts.html.twig`. **Sylius
  2.x does not read that file.** It has to go on the `sylius_shop.base#javascripts` Twig hook.
- The third argument to `encore_entry_script_tags` was omitted. It is the build name, it is
  required, and without it Encore looks in a manifest that does not contain this entry.

**How to confirm:** view the page source and look for a `<script>` whose `src` contains
`nmi-shop`. If it is absent, the tag was never rendered. If it is present but 404s, the assets were
never built or installed.

## NMI is not in the list of gateways when creating a payment method

**What you see:** *Configuration → Payment methods → Create* offers Offline, Stripe, whatever else
you have — and no NMI. It looks as though the package failed to install.

**What was missed:** README **step 2**, registering the bundle. The package is on disk and Composer
is happy; the application simply never loads it, so none of its services exist and the gateway
never registers itself.

**How to confirm:** `bin/console debug:container --parameter=kernel.bundles | grep -i nmi`.

## The first payment fails on a missing table

**What you see:** checkout gets as far as submitting the card and then throws a database error
naming `jpm_martin_sylius_nmi_transaction` — in front of a shopper.

**What was missed:** README **step 4**, the migration. Run
`bin/console doctrine:migrations:migrate`.

## The pay page redirects away with no error and nothing is charged

**What you see:** the shopper submits the card and lands back on the order page or the homepage.
No error, no flash message, no failed payment. The payment stays as it was.

**What was missed:** README **step 6** — `SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN` is
pointed at a queue rather than `sync://`. The pay page announces its command and asks for a
response *in the same request*, so a queued command leaves the page with nothing to render.

**Note this is not Sylius's default** — Sylius ships `sync://` and it is correct. Something in your
application changed it.

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

**What you see:** deliveries failing in NMI's portal, and **not a single line** in your
application's log. Not even a rejection.

**What was missed:** README **step 3** — the application never imported the plugin's *webhook*
routes. There is no endpoint, so the request never reaches the plugin and nothing can log it.

**How to confirm:** `bin/console debug:router | grep nmi_webhook`. If the route is absent, the
import is missing. If it is present, check it has **no prefix** — not the locale, not the admin
path.

## The gateway is missing from the refund plugin's methods

**What you see:** you installed `sylius/refund-plugin`, and its refund screen offers *Offline* and
nothing else. NMI is not refused — it simply never appears.

**What was missed:** the refund plugin keeps its own list of gateways it will refund through, and a
gateway missing from it is invisible rather than rejected. Add this one:

```yaml
# config/services.yaml
parameters:
    sylius_refund.supported_gateways:
        - offline
        - nmi
```

**You may not need the refund plugin at all.** Refunding from Sylius's own order screen works
without it; see *Limitations* in the README for what its four extra steps buy you.

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

**What was missed:** README **step 3** — the account routes were not imported, or were imported
without the `/{_locale}/account` prefix.

That prefix is not decoration. Sylius's access rule is `^/(?!admin|api…)[^/]++/account`, and the
first segment is the **locale** — mount the routes without it and the pages sit inside the shop
firewall but outside the rule that requires a signed-in shopper.

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
