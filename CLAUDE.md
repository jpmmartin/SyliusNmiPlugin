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

Declared constraints: `php ^8.2`, `sylius/sylius ^2.2`.

At the time of writing, `composer install` resolved:

| Package | Version |
|---|---|
| `sylius/sylius` | v2.2.8 |
| `symfony/framework-bundle` (and the Symfony stack) | v7.4.16 |
| `doctrine/orm` | 3.6.8 |
| `doctrine/dbal` | 3.10.6 |
| `doctrine/doctrine-bundle` | 2.19.0 |
| `twig/twig` | v3.28.0 |
| `sylius/twig-hooks` | v0.9.1 |
| `sylius/resource-bundle` | v1.14.2 |
| `sylius/grid-bundle` | v1.16.1 |
| `sylius/test-application` | v2.2.0-ALPHA.1 |
| `phpstan/phpstan` | 1.12.34 |
| `phpunit/phpunit` | 10.5.64 |
| `behat/behat` | v3.32.0 |
| `sylius-labs/coding-standard` | v4.5.1 |

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

## Rule 2 — Assume nothing

If something is not defined, **ask before supposing**. If the corpus (docs, `vendor/` source,
config, this file) does not settle a point, **mark it as unsettled** — do not force a plausible
answer into the gap. "The docs don't specify this; here are the two readings" is a valid, preferred
answer.

## Rule 3 — Spec-Driven Development, through OpenSpec

This project is developed with **OpenSpec** (<https://openspec.dev>). The spec is the source of
truth; code serves the spec, not the other way around. OpenSpec is not a convention we imitate
by hand — it is an installed CLI with a schema, and the schema decides the artifact names, their
order and their shape. Do not invent a parallel structure.

### The two directories

```
openspec/
├── specs/      source of truth — how the plugin works today
└── changes/    one folder per proposed change; archived changes merge into specs/
```

A change is proposed, worked, and then **archived**, which is the step that moves it into
`changes/archive/` under a date-prefixed name and merges its delta specs into `openspec/specs/`.
Until a change is archived, `openspec/specs/` does not know about it.

### The four artifacts

The active schema is `spec-driven`, declared in `openspec/config.yaml` and again per change in
`<change>/.openspec.yaml`. It defines four artifacts and the order they depend on:

| Artifact | File | Requires | Content |
|---|---|---|---|
| `proposal` | `proposal.md` | — | **Why** (1–2 sentences), **What Changes** (bullets, breaking ones marked `**BREAKING**`), **Capabilities** (which specs are created or modified), **Impact** |
| `specs` | `specs/**/*.md` | proposal | Delta specs — WHAT the system must do |
| `design` | `design.md` | proposal | **Context**, **Goals / Non-Goals**, **Decisions**, **Risks / Trade-offs**, **Open Questions** — HOW |
| `tasks` | `tasks.md` | specs **and** design | The implementation checklist |

`specs` and `design` both depend only on the proposal, so they can be written in either order;
`tasks` needs both. Implementation requires `tasks`, and progress is tracked by ticking its boxes.

**Delta spec shape**, which the schema enforces and validation checks:

```markdown
## ADDED Requirements          (also MODIFIED, REMOVED)

### Requirement: <name>
The system SHALL …             (SHALL/MUST for anything normative)

#### Scenario: <name>
- **WHEN** …
- **THEN** …
```

Every requirement needs at least one scenario. A requirement with no scenario is not a
requirement, it is a wish.

**Task shape:** `## ` numbered group headings, then `- [ ] X.Y Description; verify <how>`. The
verification clause is not decoration — a task with no stated way to check it cannot be closed
honestly.

### Working a change

Drive it through the `opsx` skills rather than editing files blind: `/opsx:propose` to create a
change and its artifacts, `/opsx:explore` to think without writing, `/opsx:apply` to implement,
`/opsx:update` to revise artifacts and keep them coherent, `/opsx:archive` when it is finished.
`openspec status --change <name> --json` reports where a change stands and which files are its
artifacts — read that rather than assuming paths.

### Gates and obligations

- **Never write implementation code while writing a proposal, spec or design.**
- Each artifact is approved before the next is built on it.
- **If implementation shows the spec is wrong or incomplete: stop, propose the amendment, get
  approval, then continue. Never diverge silently.** A change requested mid-implementation
  updates the artifacts first and the code second.
- Only tick a task when the behaviour it names is actually delivered and verified — never
  because it is nearly done, partly done or deferred. If a task turns out to describe work that
  cannot be done, rewrite the task and say so in it; do not tick it and do not quietly drop it.
- Ambiguous requirements are asked about, not invented (Rule 2).

### When it applies

New features, endpoints, integrations, schema changes, or anything touching three or more files.
Not typos, one-line fixes, dependency bumps or formatting — do those directly. In doubt, ask.

### The planning record is private, and that is deliberate

> **`openspec/` is gitignored and stays that way.** It holds this project's whole planning
> record: proposals, delta specs, designs, task lists and the evidence behind each decision.
>
> The reasons, so no future session reopens them:
> - This is a **community plugin**. What is published is the plugin. How its author documents
>   his own development is his, and is not part of what a consumer installs.
> - There is **one developer**. The artifacts coordinate him with his tools, not a team.
>
> Two consequences, stated so nobody is surprised:
> - A commit footer referencing a change and task number is a **local navigation aid only**. It
>   resolves in the author's working copy and nowhere else. Reference them anyway — they are
>   worth more to the author than they cost the reader.
> - **The repository does not back these artifacts up.** Losing the working copy loses them.
>   That is understood and accepted, and **keeping a copy is the author's own business, not work
>   this project tracks.** Do not add it as a task, do not raise it as a risk, do not offer to
>   sync it anywhere.
>
> Do not "fix" any of this by committing `openspec/`. It is a decision, not an oversight.

### Interaction between Rule 3 and Rule 1

Rule 1 fires hardest during **design**: every architectural option written into `design.md` must
be backed by pinned-version docs, `vendor/` source, or an experiment actually run — and the
design must record what was checked. A `design.md` containing an unverified claim about how
Sylius or NMI behaves is a defective design. When an experiment contradicts a published
document, the experiment wins and the design says so.

## Rule 4 — Conventional Commits

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

### Referencing the change a commit implements

Work driven by an OpenSpec change carries a footer naming the change and the task:

```
Refs: nmi-payment-gateway 4.3
```

Rule 3 already states why this resolves only in the author's working copy. Write it anyway.

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

---

# Project reference

## What this is

A Sylius payment gateway plugin integrating **NMI** (<https://nmi.com>): card payments with
browser-side tokenisation, 3-D Secure, capture, void and refund. Targets **Sylius 2.2+ only**.

Package `jpmmartin/sylius-nmi-plugin`, namespace `JpmMartin\SyliusNmiPlugin\`, MIT.

## Architecture facts, verified against Sylius 2.2.8 source

These were established by reading installed code. They are the kind of thing that costs hours to
rediscover.

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
| `sylius.payment_request.provider.http_response` | `gateway_factory` | Redirect or render on the pay page, via `HttpResponseProviderInterface` |
| `sylius.payment_request.payment_notify_provider` | priority | Resolves a `Payment` from a gateway-wide webhook. `#[AsNotifyPaymentProvider]` |
| `messenger.message_handler` | `bus: sylius.payment_request.command_bus` | The actual work |

Command messages must implement `PaymentRequestHashAwareInterface` — messenger routes on the
*interface*. XML config uses `gateway-factory="…"` (hyphen); PHP config uses `'gateway_factory' => '…'`.

`PaymentRequest` actions: `capture, authorize, refund, cancel, status, sync, payout, notify`. Its
state machine is `new → processing → completed | failed | cancelled`; all three are terminal and
there are no callbacks, so side effects happen imperatively inside the handler.

**Sale versus authorise is already core behaviour.** `DefaultActionProvider` reads
`$gatewayConfig->getConfig()['use_authorize']` and returns `ACTION_AUTHORIZE` or the default. This
costs one checkbox in the gateway configuration form — do not build branching for it.

**Payment transitions map onto NMI:**

| Transition | From → To | NMI operation |
|---|---|---|
| `complete` | `authorized` → `completed` | capture |
| `cancel` | `authorized` → `cancelled` | void |
| `refund` | `completed` → `refunded` | refund |

**There is no `void` transition — do not assume one exists.** Sylius 2.2.8 ships four definitions of
the `sylius_payment` state machine and they disagree. The two that actually load (both in
`CoreBundle`, reached via `_sylius.yaml` → `app/config.yml` → `workflow.yaml` → `workflow/**`) omit
`void` and the `unknown` place. `PaymentBundle`'s two definitions declare them but are orphaned,
because that bundle's `app/config.yml` imports only `messenger.yaml`. Reading Sylius's source and
assuming `void` exists yields an `UndefinedTransitionException` at runtime. **Voids go through
`cancel`.**

**Sylius provides no capture trigger.** Not in the admin UI, not in admin routing, not in the admin
API — all read-only for payment requests. After an `authorize`, nothing in Sylius will ever capture.
The plugin must supply the trigger.

**Every DQL query is silently ordered by id.** `SyliusCoreBundle::boot()` installs
`OrderByIdentifierSqlWalker` as a *default query hint* whenever `sylius_core.order_by_identifier`
is on, and it appends `ORDER BY <identifier> ASC` to the generated SQL without exempting
aggregates. On PostgreSQL that makes any `SUM`/`COUNT` without a matching `GROUP BY` fail with
"must appear in the GROUP BY clause". The DQL looks innocent; only the SQL shows it. Opt a query
out with `->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [])`.

**Payment-request commands must be handled synchronously.** The pay page announces the command
and asks for an HTTP response *in the same request*, so a queued command leaves the page with
nothing to render. Sylius routes `PaymentRequestHashAwareInterface` to the `payment_request`
transport, whose own default is `sync://` — but **`sylius/test-application` overrides
`SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN` to a Doctrine queue**, which silently breaks the
flow: the command is enqueued, the state never changes, and the page redirects away with no
error. `tests/TestApplication/.env` puts it back to `sync://`.

**The payment-request command bus flushes for you.** `sylius.payment_request.command_bus` carries
the `doctrine_transaction` middleware, so a handler runs inside a transaction that commits on
return. Persist inside a handler; do not flush, and do not open your own transaction.

**Free from core, do not reimplement:** gateway-config and PaymentRequest encryption; the two webhook
routes `/payment-requests/{hash}` and `/payment-methods/{code}`; duplicate suppression via
`PaymentRequestDuplicationChecker`, which prevents a second capture for the same action, payment and
method.

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

> **Symfony's translator caches the catalogue file list.** After adding a translation file,
> `rm -rf var/cache/test` before trusting a Behat result. Note the cache lives at the repository
> root, not under `tests/TestApplication/`.

There is **no Makefile**. Docker is available directly — `compose.yml` defines `php`, `mysql` 8.4,
`postgres` 16, `nginx` and `mailhog`; copy `compose.override.dist.yml` to `compose.override.yml`
first:

```bash
docker compose up -d
```

The `php` service is **built from this repository's `Dockerfile`**, not pulled: the published
`ghcr.io/sylius/sylius-php` image ships `pdo_mysql` only, and the test suite runs on PostgreSQL.
The base image is a build argument so an override can still select the xdebug variant.

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

Scaffolded from `sylius/plugin-skeleton` v2.2.0. Still present and removable once no longer needed:

- `bin/show-success.php` and `bin/validate-directory.php` — one-shot scaffolding scripts.
- The greeting/welcome demo — `src/Controller/GreetingController.php`, its templates, routes, Twig
  hooks, Behat pages and the `*greeting*` feature files. **Deliberately retained for now** as the
  only working example of how this skeleton wires Twig hooks, Behat suites and service definitions.
  Remove before the first release; `CLEANUP_GUIDE.md` describes how.
- `CLEANUP_GUIDE.md`, `RENAME_GUIDE.md`, `COMPATIBILITY_GUIDE.md` — skeleton guides, not this
  plugin's documentation. Delete them with the demo.
