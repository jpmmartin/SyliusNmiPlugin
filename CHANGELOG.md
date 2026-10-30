# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). What a version number promises
here, and what counts as a breaking change, are written down in [RELEASING.md](RELEASING.md).

## [Unreleased]

## [1.4.0] - 2026-10-30

### Added

- **Sylius 2.3.** The plugin installs and runs on Sylius 2.3 as well as 2.2: `sylius/sylius` is
  `~2.2.0 || ~2.3.0`. Sylius 2.3 passes the payment response providers the request where 2.2 passed
  its configuration: versions 1.0.0 to 1.3.2 accept Sylius 2.3 and fail to compile the container
  there, and 1.3.3 does not install on it. Both providers now take either. Built on Sylius 2.2 with PHP 8.2, and on Sylius 2.3 at both ends of
  what it admits — DBAL 3, DoctrineBundle 2 and API Platform 4 with PHP 8.3 and Symfony UX 2, and DBAL
  4, DoctrineBundle 3 and API Platform 5 with PHP 8.4 and UX 3 — each with and without the refund
  plugin. Nothing to migrate, configure or rebuild for this release itself. Moving a store to Sylius
  2.3 is Sylius's upgrade, and four parts of it touch this plugin — the refund plugin's Gaufrette
  bundle, the Encore 6 rebuild, PostgreSQL's identity columns under DBAL 4 and the new encryption
  `strict_mode` — described in *Upgrading to 1.4, and moving to Sylius 2.3* in
  [docs/upgrading.md](docs/upgrading.md).

### Changed

- **Requirements narrowed:** Symfony 7.4 (`symfony/framework-bundle` and
  `symfony/dependency-injection` `^7.4`, which also keeps Symfony 8 out: its dependency injection has
  no XML loader, and this plugin's services are XML), `doctrine/orm` 3.6 or later, and
  `sylius/twig-hooks` 0.9 or 0.14. Composer keeps a store on Symfony 6.4, Doctrine ORM 2 or twig-hooks
  0.12 or 0.13 on 1.3 — **on Sylius 2.2 only**: such a store must reach those versions before it moves
  Sylius to 2.3, or Composer gives it 1.3.2, which fails there. The refund plugin is tested at 2.2;
  Composer does not require it, but 2.1 does not boot on Sylius 2.3.
- **Raise your own constraint to `^1.4`.** Versions up to 1.3.2 accept any Sylius 2.x, so a lower
  bound lets Composer answer a Sylius minor this plugin does not support yet with one of them.

## [1.3.3] - 2026-10-29

### Security

- **A shopper can no longer have an NMI payment voided or refunded through the shop API.** Whoever
  holds an order's token can create a payment request for it through the shop API, naming its
  action. On Sylius 2.2.0 to 2.2.8, which accept any action a gateway handles, a request naming
  `refund` or `cancel` made this plugin void or refund the sale at NMI and left the payment
  completed: the order stayed paid and shipped, and the shopper had the money back. Every earlier
  version of this plugin is affected on those Sylius versions when the shop API is enabled, and on
  any Sylius version whose `sylius_api.shop_payment_request.allowed_actions` was widened to include
  either action. Sylius 2.2.9 and later refuse both by default. The plugin now sends its own refunds
  and voids itself, and a refund or void request created any other way fails without reaching NMI.
  Until you update, Sylius 2.2.9 or later with the default list of shop actions, or the shop API
  turned off, closes it.

### Changed

- **Sylius 2.2 only: `sylius/sylius` is now `~2.2.0`.** Every earlier version declared `^2.2` and so
  accepted Sylius 2.3, where it stops the store from compiling its container — the shop, the admin,
  the API and the console all fail with *Declaration of NmiHttpResponseProvider::supports(...) must
  be compatible*, whether an NMI method is configured or not. Sylius 2.3 changed the payment response
  provider interfaces this plugin implements. Support for Sylius 2.3 comes in a later release. Until
  then, a store that has resolved Sylius 2.3 with this plugin installed has to keep
  `"sylius/sylius": "~2.2.0"` in its own `composer.json`: Composer otherwise falls back to an
  earlier version of this plugin, which accepts 2.3 and fails the same way.

### Fixed

- **Refunding an NMI payment gives the money back at NMI, whichever way it is refunded.** Sylius's own
  *Refund* on the order screen — shown on a store without `sylius/refund-plugin` — marked the payment
  refunded and asked NMI for nothing, because its route announces the action as an update and the
  plugin listened only for a refund. The admin API's `PATCH /api/v2/admin/payments/{id}/refund`, and
  a store's own code applying the payment's `refund` transition, did the same. Each now refunds at
  NMI first — a void before settlement, a refund after, as the order screen was meant to — and marks
  the payment refunded only once NMI has given the money back. A refusal leaves the payment
  completed: the order screen says why in a message, as before, and the admin API answers 422.
  Refunds made through the refund plugin, and refunds NMI reports by webhook, are not sent again.
  To record money that went back outside the store — in NMI's portal on a store without webhooks,
  say — apply the transition with `['jpm_martin_sylius_nmi.already_returned' => true]` as its
  context, and NMI is not asked. **A payment refunded one of those ways before this release was
  never refunded at NMI.** The plugin's own records cannot tell those payments from refunds made in
  NMI's portal, so check in the portal: for each NMI payment marked refunded from the order screen,
  through the admin API or by your own code, the transaction should show as voided or refunded for
  the amount you meant to give back.
- **A refund this store sent is no longer taken, when NMI reports it back, for the whole payment.** NMI
  reports by webhook the refunds made through its API as well as those made in its portal, so a
  partial refund made through the refund plugin came back as a refund event and marked the whole
  payment refunded, leaving the rest impossible to give back. A refund or void event for a
  transaction this store recorded itself now changes nothing. A refund made in NMI's portal is still
  read as the whole amount, whatever part it covers.
- **A sale voided in NMI's portal before it settled marks its payment refunded.** The webhook took the
  void for a cancellation, which a completed payment cannot make, and left the payment completed with
  the money already back.
- **Paying again after an authentication NMI never answered no longer needs a reload.** When NMI
  blocked an authentication or refused its lookup, the gateway's widget called nothing back and left
  its 3-D Secure interface mounted. The plugin gave the attempt up after 45 seconds and said the card
  could not be authenticated, but every later attempt on the same page — with a new card or a saved
  one — was refused by the gateway (*You may not start another 3DS instance*) and ended the same
  way, until the page was reloaded. The plugin now frees the widget as it gives an attempt up, and
  the next press authenticates normally. A challenge nobody finished within ten minutes is now
  closed when the attempt is given up, instead of staying open on an attempt that no longer counts.
  Rebuild the front-end assets to get it.
- **A billing province no longer stops a new card from being paid.** The pay page handed 3-D Secure
  the province as Sylius keeps it — `US-FL` — and NMI refused the lookup (*billAddrState*), so no
  new card could be paid for an order billed to a province. The issuer is now told the
  two-character subdivision, `FL`; a province whose subdivision is not two characters long —
  `ES-M`, `GB-ENG` — is left out rather than sent malformed. `data-nmi-state` and the shop API's
  `responseData.state` still carry the code as Sylius keeps it, so a headless client that sends it
  to NMI's 3-D Secure has to cut it down the same way. Rebuild the front-end assets to get it.
- **A shopper whose saved card is preselected can now pay with a new card.** Choosing *Use a new
  card* showed the labels and no card fields. Collect.js sizes its frames by what it measures in
  each field when it builds them, a moment after it is configured, and the plugin configured it as
  the page loaded, with the new-card form put away behind the saved card, so every frame was built
  zero pixels high. The fields are now built the first time they are shown, a second or two after
  the shopper chooses a new card, and `nmi:mounted` is announced then; a page that opens on the
  new-card form is unchanged. The gateway's frames also get their accessible title now: it was set
  before the frames existed, so never. Rebuild the front-end assets to get it.
- **A saved card can be paid with again when it is authenticated.** With *Authenticate saved cards
  with 3-D Secure* on, the Pay button started 3-D Secure with the card's vault reference, the
  currency and the amount, which is all NMI's vault documentation asks for. NMI refused the start
  when the vault entry held no cardholder name (*customerVaultId does not include the following
  required parameters: firstName, lastName*), and a card saved while paying holds none, so the
  shopper was told the card could not be authenticated and nothing was charged. The start now
  carries the first and last name of the order's billing address. Rebuild the front-end assets to
  get it.

## [1.3.2] - 2026-09-25

### Fixed

- **An authorisation is voided whenever its order is cancelled, not only from the payment's own
  *Void*.** On a method that authorises first, cancelling the whole order — *Cancel* on the order
  screen, the admin API's `PATCH /api/v2/admin/orders/{tokenValue}/cancel`, or a store's own code
  cancelling through the state machine — left the authorisation open at NMI until it expired: the
  plugin only voided from the order screen's payment row, and Sylius cancels an order's payments
  without it. Once the cancellation is saved, the void is now queued, sent to NMI by the worker and
  recorded on the payment; if NMI refuses, its reason is recorded instead and the authorisation is left
  to expire. The cancellation itself never waits for NMI. The payment row's *Void* is unchanged.
  **A store that authorises first now needs the `main` worker**, which until now only saved cards and
  taking payment later required: `bin/console messenger:consume main`. And `main` has to stay
  asynchronous for the void to be recorded: pointed at `sync://`, it still sends the void, records
  nothing, and logs a warning. **Authorisations left open by orders cancelled before this release are
  not voided**; they expire on their own.

## [1.3.1] - 2026-09-25

### Fixed

- **A card on file is let go whenever its payment is cancelled, not only from the order screen.**
  Cancelling the whole order, `sylius:cancel-unpaid-orders` expiring an unpaid order, or a store's
  own code cancelling through the state machine left the card in NMI's vault, still held, for ever:
  the plugin only listened to the order screen's events, and Sylius cancels an order's payments
  without them. The release is now written in the same database flush as the cancellation, and the
  removal from the vault is queued once that flush has committed; a cancellation that is not saved
  releases nothing. A recurring credential is still never let go by a cancellation. **Cards already
  left held by payments cancelled before this release stay held**: nothing goes back over them.
- **A held payment can no longer be completed without an approved charge.** `PATCH
  /api/v2/admin/payments/{id}/complete`, or a store's own code applying `complete`, marked a
  payment holding a card on file — or a recurring credential it opened — paid with nothing charged
  and the card still held. The transition is now refused on every path, and the admin API answers
  422. The order screen's *Complete* still charges first, and the plugin's own charges still complete
  what they charged.

## [1.3.0] - 2026-09-24

### Added

- **Recurring charges**, for a store that sells renewals — a subscription, a membership, a plan. Off
  until the store's own code turns them on: a service the store implements or decorates,
  `NmiRecurringChargesPolicyInterface`, says which payments open recurring charges, and the one the
  plugin ships says none, so a store that does nothing sees no change. For a payment that opens them,
  the pay page shows a statement of the commitment — a neutral default the store replaces with its
  own terms, as any translation — and offers neither saving the card nor saved cards, and the
  checkout's own transaction keeps the card, declared to NMI as the first use of a stored credential:
  the sale, the authorisation, or on a method that takes payment later the verification. A guest can
  agree to it. The shop API is told `recurring_charges` when the payment is prepared.
- **Charging a renewal without the shopper**, through `NmiRecurringChargerInterface`, for a payment
  the store created — new — at that payment's own amount, which may differ from every earlier
  charge. Declared merchant-initiated and citing the first transaction, recorded against the
  renewal's payment, and answered approved, declined, refused or unknown, as the card on file's
  charger answers. It is refused before NMI is asked when the payment is not waiting, or the card was
  let go, belongs to another payment method, is closed, expired or has no first transaction to
  cite. No payment-request action can cause it. A held order on a method that takes payment later
  keeps its card for the renewals instead of on file, and *Complete* and the card on file's charger
  charge it all the same, without letting it go.
- **Letting it go** is the store's call, through `NmiRecurringCredentialReleaserInterface`; nothing
  else does it except deleting the customer. It goes through the queued purge that forgets any card
  at NMI, so it needs the worker on `main`. NMI's card updater reaches these cards too.
- A declined charge's outcome now carries NMI's response `code` beside the issuer's wording, for the
  card on file's charger as for the new one.
- **A new table**, `jpm_martin_sylius_nmi_recurring_credential`. Run your migrations when you
  upgrade; the migration only adds, and the table stays empty until the store opts in.
- **A pressed pay button now shows that the payment is being processed**: the theme's small spinner
  beside its label, a line read to screen readers ("Processing…", translated), and `aria-busy`, on
  top of the button being disabled. On the pay page's button, the saved-card path's button and the
  account area's *Add card* button; it goes when an attempt ends without leaving the page and stays
  while the page moves on. It uses the theme's Bootstrap spinner, so a theme without one shows
  nothing extra; restyle or hide it through `[data-nmi-spinner]`. **Rebuild your front-end assets**
  when you upgrade, or the old script keeps running.

### Known limitations

- **A renewal cannot be flagged as part of a recurring series.** NMI's payments API refuses the field
  its guides name for it, wherever it is placed and whatever its value, so each renewal is declared
  a merchant-initiated use of a stored credential and nothing more. The README says so, and says to
  ask NMI or the reseller which field the API takes.

## [1.2.1] - 2026-09-23

### Fixed

- **The documentation now says that saved cards and taking payment later need a worker.** Purging
  a deleted customer's saved cards at NMI, releasing a card on file, and the optional email to a
  cardholder whose saved card was closed or flagged are all queued on Sylius's `main` transport,
  which Sylius points at a database queue by default. Nothing said so; for saved cards, nothing has
  said so since they shipped. On a store running no worker a deleted customer's cards and a charged
  card stay in NMI's vault and no email is sent, with no error anywhere. The behaviour is unchanged;
  what was missing was the sentence telling you to run `bin/console messenger:consume main`, now in
  installation step 6 of the README, where saved cards and taking payment later are described, in
  the configuration reference and in the troubleshooting guide.
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

[Unreleased]: https://github.com/jpmmartin/SyliusNmiPlugin/compare/v1.4.0...main
[1.4.0]: https://github.com/jpmmartin/SyliusNmiPlugin/releases/tag/v1.4.0
[1.3.3]: https://github.com/jpmmartin/SyliusNmiPlugin/releases/tag/v1.3.3
[1.3.2]: https://github.com/jpmmartin/SyliusNmiPlugin/releases/tag/v1.3.2
[1.3.1]: https://github.com/jpmmartin/SyliusNmiPlugin/releases/tag/v1.3.1
[1.3.0]: https://github.com/jpmmartin/SyliusNmiPlugin/releases/tag/v1.3.0
[1.2.1]: https://github.com/jpmmartin/SyliusNmiPlugin/releases/tag/v1.2.1
[1.2.0]: https://github.com/jpmmartin/SyliusNmiPlugin/releases/tag/v1.2.0
[1.1.0]: https://github.com/jpmmartin/SyliusNmiPlugin/releases/tag/v1.1.0
[1.0.0]: https://github.com/jpmmartin/SyliusNmiPlugin/releases/tag/v1.0.0
