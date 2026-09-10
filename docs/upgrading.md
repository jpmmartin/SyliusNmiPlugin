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
| **PHP** | 8.2 and newer |
| **Sylius** | 2.2 and newer. Sylius 1.x is not supported and will not be |
| **Databases** | Whatever your Sylius version supports — MySQL, MariaDB or PostgreSQL. One migration serves all of them: it is written against Doctrine's schema representation, not an engine's SQL |

A release that raises the PHP or Sylius floor is a **major** release, and its upgrade note says so.

**What the schema is verified on.** Every build runs the migration on PostgreSQL and on MySQL and
checks, on each, that the tables it built are the ones the plugin's mapping describes; the rest of
the test suite runs on PostgreSQL. MariaDB is the same engine family to Doctrine and is not run
separately. Run the migration on a copy before you run it on a live store, which is good practice
whatever the engine.

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
