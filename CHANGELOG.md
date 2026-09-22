# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). What a version number promises
here, and what counts as a breaking change, are written down in [RELEASING.md](RELEASING.md).

## [Unreleased]

### Fixed

- **The documentation now says that letting go of a card needs a worker.** Releasing a card at NMI,
  and the email to a cardholder whose card was closed, are queued on Sylius's `main` transport —
  which Sylius points at a database queue by default. Nothing said so, so on a store running no
  worker a charged card stays in NMI's vault and no email is sent, with no error anywhere. The
  behaviour is unchanged; what was missing was the sentence telling you to run
  `bin/console messenger:consume main`, now in the README, the configuration reference and the
  troubleshooting guide.
- Looking an unanswered charge up in NMI's portal now says to read the amount and the time, not the
  order number alone: an order held for later payment always carries at least two transactions under
  that number — the zero-amount verification made at checkout, and every charge attempted since.
- The README no longer says that letting go of a card at NMI is **retried until NMI has done it**.
  It never was: Sylius's `main` transport tries it a few times over a few seconds and then parks it
  in `main_failed`, where `bin/console messenger:failed:retry --transport main_failed` sends it
  again. Send it again only once NMI answers — one sent again that fails is discarded, not parked a
  second time, and its vault record then has to be removed at NMI by hand. The troubleshooting guide
  has both commands and says so beside them.

## [1.2.0] - 2026-09-22

### Added

- **Take payment later**, a switch per payment method, off by default. On a method that has it,
  checkout authenticates the shopper's card with 3-D Secure for the order total and puts it on file
  at NMI for that order, **with nothing charged and nothing reserved** — a zero-amount verification
  that keeps the card. The payment waits and the order reads *awaiting payment*; the shopper lands on
  the order's confirmation, told that nothing has been charged yet, and the pay button says it saves
  the card. The option to save a card for next time and the shopper's saved cards are not offered
  there. The switch cannot be combined with *Authorize first, capture later*. The same flow works
  through the shop API, which is told `card_on_file` rather than a charge.
- **Charging a card on file without the shopper.** *Complete* on the order screen charges it first
  and completes the payment only on approval; a decline or a refusal stops it and says why. A store's
  own code charges it through `NmiCardOnFileChargerInterface`, which answers approved, declined,
  refused or unknown. Either way the charge is the payment's own amount, declared to the card
  networks as merchant-initiated and citing the verification made at checkout, with no 3-D Secure.
  It is refused before the gateway is asked when the payment is no longer waiting, holds no card on
  file, has moved to another payment method, or when the card is closed, expired or carries no
  record of that verification. A charge the gateway does not answer is reported as unknown, never as
  declined. No payment-request action can cause a charge, so neither can a client of the shop API,
  whichever actions the platform lets it name.
- A card on file is **let go at the gateway** once its payment is charged or cancelled, through the
  same queued purge that forgets a deleted customer's cards, and **NMI's card updater reaches it**: a
  closed account marks it closed, a renewal updates its expiry and number.
- **A new table.** Run your migrations when you upgrade; the migration only adds.

## [1.1.0] - 2026-09-15

### Added

- A [Symfony Flex recipe](https://github.com/symfony/recipes-contrib/tree/main/jpmmartin/sylius-nmi-plugin),
  merged into `symfony/recipes-contrib` on 2026-09-14. On a store that runs Flex — `sylius/sylius-standard`
  does — `composer require jpmmartin/sylius-nmi-plugin` registers the bundle, writes the configuration
  and route imports, adds the plugin's script to the shop entrypoint and the ignore rules for a
  generated encryption key, then prints the four things no recipe can do: the browser component, the
  build, the migration and the key. `composer remove` takes all of it out again. The recipe is kept in
  this repository under `recipe/`, compiled and installed into a fresh store on every change.

### Changed

- Step 5 of the installation is one line: the plugin's script is imported from the store's own
  `assets/shop/entrypoint.js`, as the Stripe, Mollie and Adyen plugins do, so no Encore entry and
  no Twig hook are needed. The shipped `scripts.html.twig` and the `nmi-shop` entry remain the way
  in for a store that builds under other names — one way or the other, never both. A store
  installed from 1.0.0's README keeps working unchanged.

## [1.0.0] - 2026-09-11

The first release.

### Added

- Card payments through NMI on Sylius's own pay page. The card number, expiry and verification
  value are entered inside the gateway's own frames and never reach the store, which receives a
  single-use token.
- A pay page in the store's own theme: the card fields are the theme's text inputs, labels above
  them, inside the theme's content container, with the *Pay* button as the theme's primary
  button. Read off the theme when the page loads; there is nothing to configure.
- 3-D Secure on every payment, performed in the browser. A frictionless authentication is
  invisible to the shopper; a challenge is presented in place rather than by redirecting away.
- A choice, per payment method, between charging at checkout and authorising then capturing later.
- Capture of an authorisation by hand from the order screen, or automatically when a shipment
  goes out.
- Voiding and refunding from the order screen. The plugin decides which of the two applies,
  because the gateway exposes nothing that would let a store tell them apart beforehand.
- Refunds through `sylius/refund-plugin`, when a store has it: NMI is offered for the method that
  took the order's money, with nothing to configure; a refund made there, partial or full, is sent
  to the gateway and completed only on its approval, and a refusal undoes the credit memo. The
  order screen refunds whatever is left afterwards, and the refund plugin's refund page still
  opens once the whole payment is back.
- Saved cards, off until a store turns it on and absent rather than dormant while off. A signed-in
  shopper can keep a card on file while paying, see their cards under *My account → Saved cards*,
  add one, choose the default and remove one, and pay at checkout with a saved card, the default
  already chosen. The store never holds a card number: it keeps the reference NMI gives back,
  encrypted at rest, with the four digits, brand and expiry a receipt prints. Removing a card asks
  the gateway to forget it first, and deleting a customer forgets theirs too.
- Whether a saved card authenticates again with 3-D Secure, decided per payment method. On unless a
  store turns it off, because the default is the answer to a liability question, not an accident.
- Webhooks. One endpoint per payment method takes every category NMI sends, verifies each delivery
  against the account's signing key — and refuses everything until that key is pasted — and applies
  each event exactly once, so a retry changes nothing. A refund or void performed in NMI's own
  portal lands on the order; settlement is recorded, and the store stops asking the gateway whether
  a reversal must be a refund; a saved card the issuer renewed, closed or flagged is updated, and
  the checkout stops offering a closed one.
- Chargebacks and batches that failed to settle, recorded as gateway notices under *Sales → Gateway
  notices* and never pruned. Two settings on the payment method, off by default: email the shopper
  when a saved card is closed or flagged, and report transactions the store does not recognise.
- The record of received events, which is what makes NMI's retries harmless, bounded by
  `jpm-martin:sylius-nmi:prune-received-events`: thirty days by default, and a period inside NMI's
  three-day retry window refused unless forced.
- The same flow headless, through the shop API Sylius already documents. This plugin adds no
  endpoint a headless store has to call.
- Seams for a store to build on, named in `docs/extending.md`: the charge sent to the gateway
  comes from a decorable factory and carries any field of NMI's API a store adds; the card form
  mounts on any element by its `data-nmi-*` attributes, has a `tokenize` mode, announces what it
  does as DOM events and exports `mount`, `mountAll` and `submit`. Everything the page does not
  name is internal.
- Credentials, and the gateway host, stored per payment method and encrypted at rest, so two
  channels can charge two different NMI accounts and a reseller's or white-label host is a field
  rather than a deployment.
- A record of every transaction the gateway performed, kept against the payment it belongs to and
  indexed by the gateway's own transaction identifier.
- English and Spanish translations.
- One migration for every engine Sylius supports — MySQL, MariaDB and PostgreSQL — written against
  Doctrine's schema representation rather than an engine's SQL, so Doctrine derives each engine's
  statements when it runs, nothing is skipped, and there is no copy per engine to keep in step.
  Identifiers are declared `IDENTITY`. A test compares the tables the migration built with the
  ones the mapping describes, on PostgreSQL and on MySQL, on every build.

### Known limitations

Stated here as well as in the README, because they decide whether this release fits a store:

- **No partial captures.** The gateway closes an authorisation on the first capture, so an order
  shipped in several parcels is charged in full at the first shipment.
- **Nothing NMI does outside the store reaches it until the webhook is wired.** A refund issued
  from NMI's own portal, a batch that failed to settle, a chargeback, a card the issuer reissued or
  closed: with the webhook configured, all of it reaches the order it belongs to; without it, none
  of it does, and the plugin cannot import that route for you.
- **Refunds follow the gateway's settlement rules.** Before a transaction settles, NMI's reference
  allows a void but not a refund, so a partial refund of an unsettled payment can be refused; the
  plugin asks either way, shows the answer, and the order screen's *Refund* voids the whole amount.

[Unreleased]: https://github.com/jpmmartin/SyliusNmiPlugin/compare/v1.2.0...main
[1.2.0]: https://github.com/jpmmartin/SyliusNmiPlugin/releases/tag/v1.2.0
[1.1.0]: https://github.com/jpmmartin/SyliusNmiPlugin/releases/tag/v1.1.0
[1.0.0]: https://github.com/jpmmartin/SyliusNmiPlugin/releases/tag/v1.0.0
