# Upgrading

What a version number promises you, what a release can and cannot do to your store, and what to do
when one of them needs your attention.

This is the consumer's side. [RELEASING.md](../RELEASING.md) is the author's — who tags, and what
counts as breaking when they do.

## What the version number promises

This package follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html), and that promise
is binding from the first tagged release. It is what lets you write a caret constraint and stop
thinking about it:

```json
"jpmmartin/sylius-nmi-plugin": "^1.0"
```

| Release | What it may contain | What you have to do |
|---|---|---|
| **Patch** — `1.2.3 → 1.2.4` | Bug fixes only | Nothing. Update and move on |
| **Minor** — `1.2.0 → 1.3.0` | New behaviour, new settings, new tables | Run `doctrine:migrations:migrate`. New settings arrive off, or at the default the release notes name |
| **Major** — `1.x → 2.0` | Anything, including things that break your store | Read the upgrade note. It ships with the release and tells you what to change |

**A minor release may add a database table or a column.** It will never remove or rename one, and
it will never require you to change configuration you have already written. Running the migration
after any update is the habit to have; a minor release is the case where it actually matters.

## What counts as breaking here

Not everything that changes is breaking. In this package these are, and nothing else is:

- A change to anything [docs/extending.md](extending.md) names: a hook or hookable, a template's
  path, a route, an interface or the service id it is aliased to, a value object a decorator
  receives, a JavaScript export, event or `data-nmi-*` attribute, a transition the plugin applies.
  Everything that page does not name is marked `@internal` in the code and may change in a minor
  release; a test keeps the page and the code in agreement
- An entity or schema change needing a migration that is not backward compatible
- A change to the **gateway configuration keys already stored in existing installations** — the
  keys inside `sylius_gateway_config.config`, which is where your credentials and settings live

That last one is the one that would hurt most and is the one most likely to look harmless from the
outside, which is why it is named explicitly.

## What a breaking release ships with

Three things, and a major release without all three is a mistake to report:

1. **A `BREAKING CHANGE:` footer** on the commit that caused it, saying what changed
2. **An upgrade note** in the release, describing what a consumer must do about it — not merely
   that something happened
3. **A `CHANGELOG.md` entry** under that version

## Before you upgrade

**Read [CHANGELOG.md](../CHANGELOG.md).** It is the only place that says what changed.

**Run the migration**, whatever the release:

```bash
bin/console doctrine:migrations:migrate
```

**Rebuild the front-end assets if the release touched them.** The changelog says when it did. This
is the step whose absence is invisible — the pay page renders and simply has no card fields:

```bash
yarn build
bin/console assets:install
```

**Check the new settings.** A release that adds a setting says so in the changelog, and says what
its default is. Defaults are chosen so that a store that ignores them keeps behaving as it did.

## What is supported

| | |
|---|---|
| **PHP** | 8.2 and newer; 8.3 and newer with Sylius 2.3, which requires it |
| **Sylius** | 2.2 and 2.3, from 1.4.0: `~2.2.0 \|\| ~2.3.0`. Each Sylius minor is added in a release of its own once it is tested; a minor this release does not name, it does not install on — see *Raise your own constraint* below for why that alone does not stop Composer. 1.3.3 requires `~2.2.0`; 1.0.0 to 1.3.2 declared `^2.2`, accept any later minor and fail on 2.3. Sylius 1.x is not supported and will not be |
| **Symfony** | 7.4 |
| **Databases** | Whatever your Sylius version supports — MySQL, MariaDB or PostgreSQL. One migration serves all of them: it is written against Doctrine's schema representation, not an engine's SQL |

A release that raises the PHP or Sylius floor is a **major** release, and its upgrade note says so.

**What the dependencies are tested at.** Each build runs Sylius 2.2 on PHP 8.2, and Sylius 2.3 at
both ends of what it admits: DBAL 3, DoctrineBundle 2 and API Platform 4 on PHP 8.3, and DBAL 4,
DoctrineBundle 3 and API Platform 5 on PHP 8.4 — each with and without `sylius/refund-plugin`.

**What the schema is verified on.** Every build runs the migration on PostgreSQL and on MySQL and
checks, on each, that the tables it built are the ones the plugin's mapping describes; the rest of
the test suite runs on PostgreSQL. MariaDB is the same engine family to Doctrine and is not run
separately. Run the migration on a copy before you run it on a live store, which is good practice
whatever the engine.

## Upgrading to 1.4, and moving to Sylius 2.3

**On Sylius 2.2, 1.4 changes nothing you have to do.** It adds no migration, no setting and no
asset change. What it requires is narrower than 1.3: Symfony 7.4, `doctrine/orm` 3.6 or later,
`sylius/twig-hooks` 0.9 on Sylius 2.2 or 0.14 on 2.3, and `sylius/refund-plugin` 2.2 or later if you
use it. A store on Symfony 6.4 or Doctrine ORM 2 is kept on 1.3 by Composer.

**Raise your own constraint** to `"jpmmartin/sylius-nmi-plugin": "^1.4"`. Versions 1.0.0 to 1.3.2
accept any Sylius 2.x, so with a lower bound Composer answers a Sylius minor this plugin does not
support yet by installing one of those, which then stops the store from compiling its container.
With `^1.4` it refuses the update and says why.

**Moving a store from Sylius 2.2 to 2.3** — update this plugin to 1.4 first, on Sylius 2.2, then
follow Sylius's own `UPGRADE-2.3.md`. The parts of it that touch this plugin:

- **The front end.** Sylius 2.3 builds with Webpack Encore 6, which needs Node `^22.13.0` or
  `>=24.0`; rebuild after the upgrade (`yarn install --force`, `yarn build`,
  `bin/console assets:install`). This plugin's own scripts did not change in 1.4; coming from 1.3.2
  or earlier, the rebuild is also what brings in the 1.3.3 fixes. Sylius Standard 2.3 names its
  configuration `webpack.config.mjs`; the `@vendor` alias the plugin's import uses is still there.
- **The refund plugin**, if you use it: 2.2 or later, with `KnpGaufretteBundle` registered in
  `config/bundles.php`. Sylius 2.2 brought Gaufrette itself and 2.3 does not, so a store that wired
  the refund plugin by hand may lack it, and the container then fails to load the refund plugin's
  configuration.
- **Routes, hooks and configuration**: nothing to change for NMI. The pay and after-pay URLs and the
  plugin's own routes are the same on 2.3. The `_sylius.redirect` block Sylius 2.3 took off the pay
  route was read only by Payum gateways, and this one is not one.
- **Migrations**: none from this plugin. Run Sylius's.
- **PostgreSQL with DBAL 4.** Sylius 2.3 admits DBAL 4, and under it an auto-increment id on
  PostgreSQL is an identity column, where DBAL 3 made a `SERIAL` one. On a database built under DBAL
  3, `doctrine:migrations:diff` and `doctrine:schema:update --dump-sql` then propose converting the
  plugin's six tables, and `messenger_messages`. **Do not apply that as generated**: the new identity
  starts at 1, and the next insert into a table that has rows fails on its primary key. Leave the
  columns as they are — the plugin works on them under DBAL 4 — or convert them with the procedure
  DBAL documents for exactly this, `upgrade_serial_to_identity`, in its PostgreSQL identity migration
  guide. Keeping `"doctrine/dbal": "^3.10"` in your own `composer.json` postpones the question.
- **`sylius_payment.encryption.strict_mode`**, new in 2.3: leave it off. With it on, Sylius 2.3.2's
  own encryption listener fails flushes that touch a payment method's configuration — cancelling an
  order from the admin answers 500, and a refund made through the refund plugin never reaches NMI.
  The cause is in Sylius, with or without this plugin. `allowed_classes: false` works.
- **Channels without a hostname**: from Sylius 2.2.12 and 2.3.2, links in e-mails rendered outside a
  request become relative. The cardholder e-mail this plugin sends from the `main` worker is one of
  those, so set each channel's hostname.
- **Symfony 8** is not supported yet.

## Where to report a problem

Open an issue on the repository: <https://github.com/jpmmartin/SyliusNmiPlugin/issues>. It is also
in this package's `composer.json`, so `composer show jpmmartin/sylius-nmi-plugin` will tell you
without leaving the terminal.

Useful things to include, roughly in order of how much they help:

- The plugin version, the Sylius version and the PHP version
- What you expected and what happened
- The relevant lines from `var/log/` — almost every refusal this plugin makes writes one naming
  what it refused and why
- Whether you are on a reseller or white-label gateway, if you are

**Never paste a security key, a signing key, or a customer's card details**, even partially. The
last four digits are fine; nothing else is.

If you think you have found a security problem, do not open a public issue. Report it privately
through the repository's security advisory form.
