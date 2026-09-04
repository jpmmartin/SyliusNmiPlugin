# Releasing

This package is installed with a caret constraint, so its version number is a promise to every
store that depends on it. This file says who makes that promise, what it means, and how a release
is cut.

## Who tags

**The maintainer of this repository, and nobody else.** There is one, and releases are not
automated: a tag is pushed by hand, after the checks below pass. Contributors open pull requests;
merging one does not release anything.

## What the version number promises

The commit type decides the next number, and
[Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/) is how it is declared:

| Commits since the last tag | Next release |
|---|---|
| `fix:` only | PATCH — 1.0.0 → 1.0.1 |
| any `feat:` | MINOR — 1.0.0 → 1.1.0 |
| any `!` or `BREAKING CHANGE:` footer | MAJOR — 1.0.0 → 2.0.0 |

## What counts as a breaking change

In this package, and only these:

- A change to a **public interface, service id or DI tag** another plugin could be decorating or
  replacing.
- An **entity or schema change whose migration is not backward compatible** — anything a store
  cannot roll forward without downtime or data loss.
- A change to the **gateway configuration keys already stored in existing installations**, which
  live encrypted in the database and are not rewritten by an upgrade.

A change that is any of those requires all three of:

1. `!` after the type or scope in the commit subject — `feat(gateway)!: …`;
2. a `BREAKING CHANGE:` footer in that commit explaining **what a store must do to upgrade**, not
   merely what changed;
3. an entry under `### Changed` or `### Removed` in the changelog repeating that migration note.

A breaking change without a migration note is not releasable. The footer is the only place a store
finds out what to do, and by the time they read it the upgrade has already failed.

## Cutting a release

1. `composer check` is green — ECS, PHPStan, PHPUnit and Behat — in **both** CI configurations,
   with and without `sylius/refund-plugin` registered.
2. Move the `## [Unreleased]` section of `CHANGELOG.md` under the new version heading with today's
   date, and open a fresh empty `## [Unreleased]`. Update the link definitions at the foot of the
   file.
3. Commit that as `docs: release X.Y.Z`.
4. Tag it — `git tag -a vX.Y.Z -m "X.Y.Z"` — and push the commit and the tag.
5. Packagist publishes from the tag. There is nothing to upload.

## The development branch alias

`composer.json` aliases `dev-main` to `1.0-dev` so a store can follow the branch under a `^1.0`
constraint. If this plugin's major is ever made to track Sylius's instead, that alias and the
changelog's first version have to move together — they are two halves of the same promise.
