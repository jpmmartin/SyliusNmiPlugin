# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

This is a **public, MIT-licensed package**. Everything here must make sense to a stranger who clones
it — no machine-local paths, no references to a private application.

---

# How we work here

These rules govern *how* to work in this repo. They take precedence over the reflex to answer fast.

**What belongs in this file:** durable rules and non-obvious facts that survive the next commit.
**What does not:** snapshots of mutable state — whether a commit exists, whether a directory has
been created yet, what today's branch is. Anything a shell command answers in one second does not
belong here, because a stale note reads as verified truth and is worse than no note. State each
fact once, in the section it topically belongs to; never in two places.

## Rule 1 — Read the real documentation before saying anything

Before offering an opinion, an option, a recommendation, or a decision that will materialize in
**Symfony, Sylius, Doctrine, NMI's API, or any library in `composer.json`/`package.json`** — consult
the official documentation **for the versions this package actually resolves**, via `context7`,
*before* writing the options down.

This is not conditional on someone naming a library. It applies even when no library is mentioned:
in a payment gateway plugin practically every decision lands on one of them.

After consulting:
- **If what the docs return changes the options** → reformulate the question/answer and state
  explicitly *what changed and why*.
- **If it does not change them** → say that too ("checked the docs for X vX.Y, the options stand").

Silence about having checked is not acceptable. The reader must be able to tell the difference
between a verified answer and an unverified one.

### Versions to query against

Never query "latest". Derive the real numbers from `composer.lock` — but note **`composer.lock` is
gitignored** (correct for a library), so a fresh clone has none until `composer install` runs.

Declared constraints: `php ^8.2`, `sylius/sylius ~2.2.0 || ~2.3.0`, `doctrine/orm ^3.6`,
`sylius/twig-hooks ^0.9 || ^0.14`, Symfony `^7.4`.

There is no single answer any more: the build pins three legs (`.github/workflows/build.yaml`), and
which one you are on decides the version to query. At the time of writing they resolved:

| Package | Sylius 2.2 leg | Sylius 2.3, low | Sylius 2.3, high |
|---|---|---|---|
| `sylius/sylius` | v2.2.12 | v2.3.2 | v2.3.2 |
| `symfony/framework-bundle` (and the Symfony stack) | v7.4.22 | v7.4.22 | v7.4.22 |
| `doctrine/orm` | 3.6.9 | 3.6.9 | 3.6.9 |
| `doctrine/dbal` | 3.10.6 | 3.10.6 | 4.5.0 |
| `doctrine/doctrine-bundle` | 2.19.3 | 2.19.3 | 3.3.4 |
| `doctrine/persistence` | 3.4.5 | 4.2.1 | 4.2.1 |
| `api-platform/symfony` | v4.4.6 | v4.4.6 | v5.0.5 |
| `symfony/ux-twig-component` | v2.36.0 | v2.36.0 | v3.6.0 |
| `twig/twig` | v3.30.0 | v3.30.0 | v3.30.0 |
| `sylius/twig-hooks` | v0.9.1 | v0.14.1 | v0.14.1 |
| `sylius/resource-bundle` | v1.14.2 | v1.14.2 | v1.14.2 |
| `sylius/grid-bundle` | v1.16.1 | v1.16.1 | v1.16.1 |
| `sylius/test-application` | v2.2.0-ALPHA.1 | v2.3.0-ALPHA.4 | v2.3.0-ALPHA.4 |
| `sylius/refund-plugin` | v2.2.1 | v2.2.1 | v2.2.1 |
| `phpstan/phpstan` | 1.12.34 | 1.12.34 | 1.12.34 |
| `phpunit/phpunit` | 10.5.66 | 10.5.66 | 10.5.66 |
| `behat/behat` | v3.34.0 | v3.34.0 | v3.34.0 |
| `sylius-labs/coding-standard` | v4.5.1 | v4.5.1 | v4.5.1 |

The low 2.3 leg runs on PHP 8.3, which is what keeps it on Symfony UX 2; on PHP 8.4 or later the same
pins resolve UX 3. A plain `composer install` resolves whatever is newest, which is none of these.

Re-derive rather than trusting this table after any `composer update`.

### How `context7` reaches this session

context7 is an MCP server. It may arrive as a marketplace **plugin** (tools appear as
`mcp__plugin_context7_context7__*`) or as a project-scoped server in a `.mcp.json` (tools appear as
`mcp__context7__*`). This repository declares neither — it relies on whatever the developer has
configured.

**If context7 tools are not visible in the current session, fall back to the sources below and say
so.** Never present an unverified answer as a checked one.

### Fallback sources (also the tiebreakers when context7 disagrees)

In order of authority:
1. **`vendor/` source and config** — the *exact* pinned code that runs here, so it outranks context7
   and any published doc page when they disagree. context7 indexes the upstream repo; `vendor/` is
   what executes. Grep the bundle's `Resources/config/*` and `src/`.
2. `vendor/bin/console debug:*` / `config:dump-reference` for what the container actually resolves.
3. Official docs, version pinned in the URL: <https://docs.sylius.com>,
   <https://symfony.com/doc/7.4/>, <https://www.doctrine-project.org/>, <https://docs.nmi.com>.

When an experiment actually run contradicts a published document, the experiment wins, and
whatever records the decision says so.

## Rule 2 — Assume nothing

If something is not defined, **ask before supposing**. If the corpus (docs, `vendor/` source,
config, this file) does not settle a point, **mark it as unsettled** — do not force a plausible
answer into the gap. "The docs don't specify this; here are the two readings" is a valid, preferred
answer.

## Rule 3 — Conventional Commits

Every commit message follows [Conventional Commits v1.0.0](https://www.conventionalcommits.org/en/v1.0.0/):

```
<type>[optional scope][!]: <description>

[optional body]

[optional footer(s)]
```

**Types.** Only `feat` and `fix` are mandated by the spec. The rest is the conventional
(Angular-derived) set we also use here — stick to this list, do not invent new ones:

| Type | Use for |
|---|---|
| `feat` | New behaviour visible to a shop or admin user, or a new public API affordance |
| `fix` | Bug fix |
| `docs` | Documentation only, including this file and the README |
| `refactor` | Restructuring with no behaviour change |
| `perf` | Performance work |
| `test` | PHPUnit or Behat only |
| `build` | Composer/npm dependencies, webpack, Docker |
| `ci` | CI workflow configuration |
| `chore` | Repo scaffolding and tooling that fits nothing above |
| `style` | Formatting only — never mixed with other types |
| `revert` | Reverting a previous commit |

Note `docs`, not `doc`.

### SemVer is binding here

> This is a **published package**. Consumers install it with a caret constraint, so the commit type
> is a promise about the next release number:
>
> | Commit | Release |
> |---|---|
> | `fix` | PATCH |
> | `feat` | MINOR |
> | `!` or a `BREAKING CHANGE:` footer | MAJOR |
>
> A breaking change needs `!` after the type/scope **and** a `BREAKING CHANGE:` footer explaining the
> migration. In this package that means: a change to a public interface, service id or DI tag another
> plugin could rely on; an entity or schema change requiring a non-backward-compatible migration; or
> a change to the gateway configuration keys already stored in existing installations.
>
> This obligation starts at the first tagged release and never relaxes.

## Rule 4 — Which test proves what

Sylius's own answer, from *Architectural Drivers*: **all of the functionality it provides is
described as user stories using Behat scenarios.** Its plugins follow it. `sylius/refund-plugin`
carries 71 scenarios across 37 feature files, every one named for the goal —
`refunding_order_units.feature`, `browsing_list_of_all_credit_memos.feature` — and none for a
class; `Sylius/PayPalPlugin` reads the same. Neither ships phpspec any more, and PayPalPlugin
keeps `tests/{Unit,Functional,Behat}` side by side, so PHPUnit at the HTTP level is normal in an
official plugin too.

So, here: **a functionality earns a Behat scenario when a person performs it through a page, and
it can be driven without a real browser.** Both halves are required.

- The first decides how the file reads: the role's own words, `Feature` / `In order to` / `As a` /
  `I want`, one file per goal, `snake_case` — Sylius's naming convention, not ours.
- The second is why the money path is not in Behat. The card fields and 3-D Secure live in
  frames served by the gateway, and `composer check` runs Behat with `~@javascript`, so a
  scenario that needs Chrome proves nothing in the gate. `@javascript` scenarios are allowed —
  tag them `@ui @javascript`, as Sylius does — but they belong to `composer behat-js`, which the
  gate does not run.

Everything else is PHPUnit: `Functional` for what a request does — endpoints, forms, pages,
security probes — `Integration` for what reaches the database, `Unit` for a decision inside one
class. The shop JavaScript has its own `node --test` files, and the documented installation is
proven by the Install workflow rather than by any of these.

A scenario nobody can fail is worse than no scenario: if the interesting part cannot be reached
without a browser, write the PHPUnit test that can be reached and say so, rather than a Behat
scenario that asserts the easy half.

---

# Project reference

## What this is

A Sylius payment gateway plugin integrating **NMI** (<https://nmi.com>): card payments with
browser-side tokenisation, 3-D Secure, capture, void and refund. Targets **Sylius 2.2 and 2.3**:
`~2.2.0 || ~2.3.0`. Each minor is added deliberately rather than by a caret, because the encryption
contracts the plugin implements are still `@experimental` in 2.3.2.

Package `jpmmartin/sylius-nmi-plugin`, namespace `JpmMartin\SyliusNmiPlugin\`, MIT.

## Architecture facts, verified against Sylius 2.2.9 and 2.3.2 source

These were established by reading installed code — 2.2.9 first, then each one again against 2.3.2
at both ends of the dependencies it admits. They are the kind of thing that costs hours to
rediscover. Where the two minors differ, the fact says so.

**Sylius 2.3 changed two interfaces the plugin implements.** `HttpResponseProviderInterface` and
`AfterPayResponseProviderInterface` take the `Request` in 2.3 where 2.2 passed the
`RequestConfiguration`, and a class implementing the 2.2 signature is a compile error the moment
the container is built — the whole store goes down, NMI configured or not. The plugin's two
providers take `RequestConfiguration|Request`, which satisfies both. Every published version up to
1.3.2 declared `^2.2` and fails on 2.3; Composer falls back to them whenever a newer one is excluded.

**2.3 made the PaymentRequest contracts stable, not the encryption ones.** `UPGRADE-2.3` says
`@experimental` left all of `Sylius\Component\Payment`; `vendor/` disagrees, and `vendor/` wins:
`EntityEncrypterInterface`, `EncryptionAwareInterface`, `EncryptionCheckTrait`, `EncrypterInterface`
and `EntityEncryptionListener` are still `@experimental` in 2.3.2, and the plugin implements,
extends or instantiates all of them. That is why the constraint names minors.

**PaymentRequest is a Payum-free path.** `Sylius\Bundle\PaymentBundle` contains no Payum reference;
the dispatch key is a plain string from `GatewayConfig`. `SyliusPayumBundle` consumes the same public
extension points rather than being a prerequisite. **A new gateway must not touch Payum.**

**The extension contract.** There is no `PaymentRequestHandlerInterface` and no
`GatewayFactoryInterface`. A "gateway factory" is a string asserted into existence by tagging a form
type. The pattern is **CommandProvider → Messenger handler**:

| Tag | Indexed by | Purpose |
|---|---|---|
| `sylius.gateway_configuration_type` | `type`, `label` | Declares the factory name. Autoconfigured from `#[AsGatewayConfigurationType]`; also needs `form.type` |
| `sylius.payment_request.command_provider` | `gateway_factory` | Primary hook. One `ActionsCommandProvider` per factory, fed by a plugin-private `tagged_locator(…, 'action')` |
| `sylius.payment_request.provider.http_response` | `gateway_factory` | Redirect or render on the pay page, via `HttpResponseProviderInterface` (takes the `Request` from 2.3) |
| `sylius.payment_request.payment_notify_provider` | priority | Resolves a `Payment` from a gateway-wide webhook. `#[AsNotifyPaymentProvider]` |
| `messenger.message_handler` | `bus: sylius.payment_request.command_bus` | The actual work |

Command messages must implement `PaymentRequestHashAwareInterface` — messenger routes on the
*interface*. XML config uses `gateway-factory="…"` (hyphen); PHP config uses `'gateway_factory' => '…'`.

`PaymentRequest` actions: `capture, authorize, refund, cancel, status, sync, payout, notify`. Its
state machine is `new → processing → completed | failed | cancelled`; all three are terminal and
there are no callbacks, so side effects happen imperatively inside the handler.

**The shop API takes only the actions on a list, and the list is short.** Since 2.2.9,
`sylius_api.shop_payment_request.allowed_actions` — default `capture, authorize, status, notify` —
is enforced by `ChosenPaymentRequestActionEligibilityValidator` before a payment request exists, so
a shop client naming anything else gets HTTP 422 and nothing is created. On 2.2.8 any action with a
command provider was accepted. A refusal that belongs to the plugin therefore only runs on 2.2.8 or
on a store that widened the list, and a test asserting one refusal passes on one version and fails on
the other. The admin API and the admin screens need no such list because neither can create a payment
request: the admin API declares only read operations on it, `Get` and `GetCollection`, and
`AdminBundle` routes only `index` and `show`, both `GET`. Holds unchanged on 2.3.2. The plugin does
not lean on the list for money: it sends its own `refund` and `cancel` commands to the bus itself,
and its providers for those two actions refuse every request that is announced instead.

**Sale versus authorise is already core behaviour.** `DefaultActionProvider` reads
`$gatewayConfig->getConfig()['use_authorize']` and returns `ACTION_AUTHORIZE` or the default. This
costs one checkbox in the gateway configuration form — do not build branching for it.

**Payment transitions map onto NMI:**

| Transition | From → To | NMI operation |
|---|---|---|
| `complete` | `authorized` → `completed` | capture |
| `cancel` | `authorized` → `cancelled` | void |
| `refund` | `completed` → `refunded` | refund |

**There is no `void` transition — do not assume one exists**, in 2.2 or 2.3. Sylius 2.2.9 ships four definitions of
the `sylius_payment` state machine and they disagree. The two that actually load (both in
`CoreBundle`, reached via `_sylius.yaml` → `app/config.yml` → `workflow.yaml` → `workflow/**`) omit
`void` and the `unknown` place. `PaymentBundle`'s two definitions declare them but are orphaned,
because that bundle's `app/config.yml` imports only `messenger.yaml`. Reading Sylius's source and
assuming `void` exists yields an `UndefinedTransitionException` at runtime. **Voids go through
`cancel`.**

**Sylius provides no capture trigger.** Not in the admin UI, not in admin routing, not in the admin
API — all read-only for payment requests. After an `authorize`, nothing in Sylius will ever capture.
The plugin must supply the trigger.

**The order screen's events are not the only way a payment moves.** The `sylius.payment.pre_*` and
`post_*` resource events fire only from the admin controller's routes, and each is named after the
route's `event` — absent one, after the controller action. `sylius_admin_order_payment_complete`
declares `event: complete`; `sylius_admin_order_payment_refund` declares none, so Sylius's own
*Refund* fires `sylius.payment.pre_update`, never `pre_refund` (`ResourceController::
applyStateMachineTransitionAction` dispatches `ResourceActions::UPDATE`). Sylius cancels an order's
payments through the state machine (`CancelPaymentListener`, on `workflow.sylius_order.completed.cancel`),
`sylius:cancel-unpaid-orders` cancels orders the same way, and the admin API's
`PATCH /payments/{id}/complete` and `PATCH /payments/{id}/refund` apply their transitions
directly — none of them fires those events. What must hold on every path hangs on the flush that
saves the change, or on a `workflow.sylius_payment.transition.*` listener — never on the guard,
which also answers `can()`, and the order screen draws *Complete* only when `can()` says yes. The order screen's transitions run
inside a transaction: Sylius Core decorates `sylius.resource_controller.resource_update_handler` with
`CoreBundle/Doctrine/ORM/Handler/ResourceUpdateHandler`, which opens one around `apply()` and
`flush()` and rolls it back only on `OptimisticLockException` — any other exception propagates with
it still open and uncommitted. A store that maps `sylius_payment`
to `winzou_state_machine` gets no workflow events at all. And an `onFlush` listener that changes an
entity encrypted by `EntityEncryptionListener` must run above that listener: it encrypts only what
is already scheduled when it runs.

**DQL queries may be silently ordered by id.** `SyliusCoreBundle::boot()` installs
`OrderByIdentifierSqlWalker` as a *default query hint* whenever `sylius_core.order_by_identifier`
is on, and it appends `ORDER BY <identifier> ASC` to the generated SQL without exempting
aggregates. On PostgreSQL that makes any `SUM`/`COUNT` without a matching `GROUP BY` fail with
"must appear in the GROUP BY clause". The DQL looks innocent; only the SQL shows it. Opt a query
out with `->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [])`. The setting defaults to on in 2.2 and to
off in 2.3, so keep the opt-out — a 2.2 store, or a 2.3 store that turns it on, still gets the
walker — and know that only the 2.2 build exercises it.

**Payment-request commands must be handled synchronously.** The pay page announces the command
and asks for an HTTP response *in the same request*, so a queued command leaves the page with
nothing to render. Sylius routes `PaymentRequestHashAwareInterface` to the `payment_request`
transport, whose own default is `sync://` — but **`sylius/test-application` overrides
`SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN` to a Doctrine queue**, which silently breaks the
flow: the command is enqueued, the state never changes, and the page redirects away with no
error. `tests/TestApplication/.env` puts it back to `sync://`.

**The plugin's queued work rides `main`, and `main` gives up.** The extension prepends routing of
`PurgeStoredCard`, `NotifyCardholder` and `VoidAuthorization` to Sylius's `main` transport — default
DSN `doctrine://default`, so nothing runs without a worker — and sets no retry strategy of its own.
`main`'s is `max_retries: 3`, `delay: 1000`, `multiplier: 2`, `max_delay: 0`: a failing message is
tried again after about one, two and four seconds, then parked in `main_failed`. Parking resets the
retry count. A message resent from `main_failed` that fails again is retried under `main_failed`'s
own strategy and then **discarded** with a `critical` log line, because `main_failed` has no failure
transport. So "never lost" holds once, not twice — never promise a purge is retried until it
completes. `tests/Integration/StoredCard/NmiParkedPurgeTest` runs both halves through a real worker.
Since symfony/messenger 7.4.22 a worker *acknowledges* a failed attempt it will retry, having sent the
retry, and rejects only the last; read whether a message was handled off its `HandledStamp`, never
off the acknowledgement.

**Work queued from a flush is dispatched on `sylius.event_bus`, and its handler must not write from
inside that flush.** Sylius puts Symfony's `doctrine_transaction` middleware on `sylius.command_bus`,
and it flushes on every dispatch, the sending side included
(`doctrine-bridge/Messenger/DoctrineTransactionMiddleware.php`), so a dispatch from `postFlush` on that
bus flushes inside the flush. A store that points `main` at `sync://` runs the handler right there, in
the `postFlush` that dispatched it, before the unit of work is cleaned up. The purge writes nothing, so
it does not care. The void does write, so its listener marks itself while it dispatches and the
handler, finding the mark, sends the void without recording it. Asking Doctrine instead does not work:
`executeInserts()` and `executeUpdates()` take each entity off the schedule as they write it
(`UnitOfWork.php:1141`, `:1231` in ORM 3.7.1; `:1115`, `:1201` in 3.6.9), so inside `postFlush` `isScheduledForUpdate()` is false for
everything the flush just wrote.

**The payment-request command bus flushes for you.** `sylius.payment_request.command_bus` carries
the `doctrine_transaction` middleware, so a handler runs inside a transaction that commits on
return. Persist inside a handler; do not flush, and do not open your own transaction.

**Free from core, do not reimplement:** gateway-config and PaymentRequest encryption; the two webhook
routes `/payment-requests/{hash}` and `/payment-methods/{code}`; duplicate suppression via
`PaymentRequestDuplicationChecker`, which prevents a second capture for the same action, payment and
method. Sylius 2.3 adds `sylius_payment.encryption.strict_mode`, off by default; with it on, Sylius
2.3.2's own `EntityEncryptionListener` decrypts an already decrypted `GatewayConfig` on `postFlush` and
throws, with or without this plugin — cancelling an order from the admin answers 500 and a refund
plugin refund never reaches NMI. Documented for stores, not worked around.

**Sylius 2.3 moved things the test suite stands on.** Its test application no longer registers
`KnpGaufretteBundle`, which refund-plugin 2.2 needs; its Behat suites are `suites.php` instead of
`suites.yml`; and its payment-method context requires `Sylius\Behat\Page\Admin\PaymentMethod\IndexPage`,
which 2.2 does not have. Each is handled by a guard that holds on both minors —
`tests/TestApplication/config/bundles.php`, `tests/Behat/Resources/sylius_suites.php`, and the
`class_alias` at the top of the plugin's own index page. A Sylius-Standard skeleton asks for
`sylius/sylius` with a caret, so the 2.2 skeleton resolves 2.3 unless the store pins it, and the 2.2
skeleton registers `FOSRestBundle`, which only `sylius/paypal-plugin` 2.0 brought: the Install
workflow pins both.

**The public surface is a list, and the list is tested.** `docs/extending.md` names every seam a
store may rely on: hooks, templates, routes, interfaces with their service aliases, value objects,
the JavaScript exports, events and `data-nmi-*` attributes, the transitions applied. Every class
under `src/` is either named there or carries `@internal`, and `tests/Unit/Docs/ExtendingSurfaceTest`
fails when either half drifts. A new class starts internal; making it public is a decision taken in
the document, not by leaving the tag off. SemVer is promised about the list and nothing else.

**The Flex recipe is sourced here and served from contrib.** `recipe/jpmmartin/sylius-nmi-plugin/1.0/`
is laid out exactly as `symfony/recipes-contrib` is, so a pull request there is a copy of that
directory, and the Install workflow compiles it with `symfony-tools/recipes-checker`, serves it to
a throwaway store and installs through it on every change — the recipe is tested here before
contrib ever sees it. That workflow's `recipe_source` input switches the same job to contrib's own
endpoint, which is how what a reader gets is checked after a merge rather than before. It does not touch the encryption key path: a store may already hold
credentials encrypted with the skeleton's key. The plugin's script reaches a store one of two ways,
never both — the import line in `assets/shop/entrypoint.js`, which is what the recipe and the
README's step 5 do, or the shipped `scripts.html.twig` with an `nmi-shop` Encore entry; two loads
mount the card form twice, and the gateway's component allows one.

**Migrations are written once, for every engine, against the schema object.** `up(Schema $schema)`
describes the tables; Doctrine derives each engine's statements when the migration runs, so there
is no MySQL copy, no PostgreSQL copy and nothing that skips itself — unlike Sylius's own migrations,
which ship in per-engine pairs. `doctrine:migrations:diff` cannot produce such a file: it emits the
connected engine's SQL behind a platform guard, by design. The mapping is the truth, and
`tests/Integration/Migrations/MigrationMatchesMappingTest` holds the migration to it: it compares
the tables the migration built with the ones the ORM expects and fails on any difference, on
PostgreSQL in the main jobs and on MySQL in a job of its own, each under DBAL 3 and DBAL 4. Ids are
`IDENTITY` in the mapping, never `AUTO`: `AUTO` resolves to a sequence on PostgreSQL under DBAL 3
and to an identity column everywhere else — and Sylius 2.3 forces it to a sequence on PostgreSQL —
so one migration cannot match both. `IDENTITY` is a `SERIAL` column on PostgreSQL under DBAL 3 and
`GENERATED BY DEFAULT AS IDENTITY` under DBAL 4, which the one migration builds correctly on a fresh
database either way; but a store that moves an existing database to DBAL 4 is offered a conversion
of every such table, the plugin's six and `messenger_messages`, and applying it as Doctrine
generates it restarts the ids at 1. `docs/upgrading.md` sends stores to DBAL's own
`upgrade_serial_to_identity` instead.

**Reference implementation:** `flux-se/sylius-stripe-plugin` is the closest model on the modern
contract — install it in a scratch project and read it. `sylius/adyen-plugin` predates
`PaymentRequest` and uses its own command bus; useful for packaging and Twig Hooks, misleading for
the payment-request contract.

## Repository layout

```
src/JpmMartinSyliusNmiPlugin.php                          bundle class (AbstractResourceBundle + SyliusPluginTrait)
src/DependencyInjection/JpmMartinSyliusNmiExtension.php   service loading, resources, migrations namespace
src/DependencyInjection/Configuration.php
src/Gateway/                                              client, configuration, responses, exceptions
src/Command/  src/CommandProvider/                        payment-request messages and their providers
src/Entity/  src/Repository/  src/Migrations/             the transaction log
src/Form/Type/                                            gateway configuration form
config/services.xml  config/services/**                   services.xml imports services/**
config/doctrine/model/                                    the entity mapping, next to the rest of the config
config/routes/  config/twig_hooks/
templates/{admin,shop}/          assets/{admin,shop}/          translations/
features/                        Behat feature files
tests/{Unit,Integration,Functional,Behat,TestApplication}/
```

## Development commands

The plugin is exercised through `sylius/test-application`, which supplies the kernel and console.

```bash
composer install
composer check                   # ECS, then PHPStan, then PHPUnit, then Behat — the gate
vendor/bin/console               # test-application's console
vendor/bin/phpunit               # suites: Unit, Integration, Functional
vendor/bin/behat --no-interaction
vendor/bin/phpstan analyse src/
vendor/bin/ecs check src tests
```

`sylius/test-application` supplies no runner configuration of its own, so `phpunit.xml.dist`,
`behat.yml.dist`, `phpstan.neon` and `ecs.php` are this repository's, written from scratch. The
PHPUnit bootstrap is `tests/bootstrap.php`, which defaults `APP_ENV` to `test` before handing
over to the test application's own bootstrap.

> **Behat prompts for snippet generation** when a step is undefined, which looks exactly like a
> hang. Always pass `--no-interaction`; the composer scripts already do.

> **Sylius's translator does not notice a new translation file.** The theme bundle's translator
> keys its catalogue caches by the fallback locales alone and watches only the files it loaded
> when it dumped them, so a file added later is never read until the cache is deleted. After
> adding a translation file, `rm -rf var/cache/test` before trusting a Behat result. Note the
> cache lives at the repository root, not under `tests/TestApplication/`.

> **The test environment's clock cannot be frozen.** There, `clock` is an alias of
> `sylius.behat.clock` (`Sylius\Behat\Service\Clock`), which returns the date written in
> `vendor/sylius/test-application/var/date.txt` when that file exists and the real time otherwise,
> has a `sleep()` that does nothing, and never consults Symfony's global clock — so
> `ClockSensitiveTrait::mockTime()` does not move it. The in-memory Messenger transports are built
> with it, so a test that drives retries through a worker waits out every `DelayStamp` in real time.
> Moving it means writing that file — which is how Sylius's own Behat steps do it
> (`Setup\CalendarContext::itIsNow()` writes it, `Hook\CalendarContext` deletes it after each
> scenario) — and a test that did the same would share state with them; don't.

There is **no Makefile**. Docker is available directly — `compose.yml` defines `php`, `mysql` 8.4,
`postgres` 16, `nginx` and `mailhog`; copy `compose.override.dist.yml` to `compose.override.yml`
first:

```bash
docker compose up -d
```

The `php` service is **built from this repository's `Dockerfile`**, not pulled: the published
`ghcr.io/sylius/sylius-php` image ships `pdo_mysql` only, and the test suite runs on PostgreSQL.
The schema alone is also built and checked on MySQL — in continuous integration in a job of its
own, and locally against the `mysql` service. The base image is a build argument so an override
can still select the xdebug variant.

### Composer scripts — one of them destroys data

```bash
composer frontend-clear     # yarn install && yarn build in the test application, then assets:install
composer database-reset     # DROPS the database, recreates, migrates, loads fixtures
composer test-app-init      # database-reset + frontend-clear
```

> `database-reset` begins with `doctrine:database:drop --force --if-exists`, and `test-app-init`
> chains it. Both act on whatever `DATABASE_URL` resolves to at that moment. **Never run them
> reflexively.** The skeleton originally wired `test-app-init` into `post-create-project-cmd`; that
> hook has been removed, so nothing triggers it automatically any more.

Database credentials for the test application live in `tests/TestApplication/.env` and `.env.test`.

## Skeleton leftovers

Scaffolded from `sylius/plugin-skeleton` v2.2.0. The greeting demo, the three skeleton guides and
the two one-shot `bin/` scripts are gone; what remains of the scaffold is the directory layout,
which is the part worth keeping.

One piece is still there, and staying is a decision that was taken rather than a leftover nobody
looked at. `features/core_features_still_pass.feature` — renamed from
`running_a_sylius_feature.feature` when the decision was made — is a copy of Sylius's
channel-management feature, shipped by the skeleton to show that a plugin can run the framework's
scenarios. **It is kept because it is the only place the "no effect until configured" requirement
is exercised through the interface:** it runs under Sylius's own imported suite, and it passes
only while registering this plugin leaves a core admin flow alone. The cost is real and accepted —
its steps are Sylius's, so it can break for reasons that have nothing to do with the gateway, and
when it does, this plugin's registration is the first suspect, not the gateway. The file says all
of this at its top, so nobody deletes it as scaffolding.
