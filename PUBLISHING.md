# Publishing the UptimeX Laravel Client

This document is for **maintainers** of the SDK. It explains how the package
is published from inside the UptimeX monorepo to the standalone public
GitHub repository and on to Packagist.

> **TL;DR**
> The SDK lives at `packages/laravel-client/` inside the UptimeX monorepo
> for development convenience. Releases are pushed to a dedicated public
> repo via `git subtree split`. Tagging that public repo automatically
> publishes to Packagist via webhook.

---

## Topology

```
┌─────────────────────────────────────────────┐
│  Monorepo (private)                          │
│  github.com/Purposeeco/uptimeX-Laravel       │
│                                              │
│  packages/laravel-client/  ←── work happens here
└─────────────────────────────────────────────┘
                       │
                       │  git subtree split
                       ▼
┌─────────────────────────────────────────────┐
│  Public SDK repo                             │
│  github.com/Purposeeco/uptimex-laravel-      │
│  client-compser   ←── consumers see this     │
└─────────────────────────────────────────────┘
                       │
                       │  webhook on tag
                       ▼
┌─────────────────────────────────────────────┐
│  Packagist                                   │
│  packagist.org/packages/uptimex/             │
│  laravel-client                              │
└─────────────────────────────────────────────┘
```

---

## One-time setup (already done; documented for reference)

```bash
# Inside the monorepo root
git remote add sdk-public \
    https://github.com/Purposeeco/uptimex-laravel-client-compser.git

# Initial extract + push
git subtree split -P packages/laravel-client -b sdk-export
git push sdk-public sdk-export:main
```

After the first push, [submit the repo to Packagist](https://packagist.org/packages/submit)
and install the **Packagist GitHub App** on the public repo so future
tag pushes auto-sync.

---

## Cutting a release

From the monorepo root, after merging your SDK changes to `main`:

```bash
# 1. Re-extract the SDK history including the new commits
git subtree split -P packages/laravel-client -b sdk-export

# 2. Push the latest state to the public repo's main branch
git push sdk-public sdk-export:main

# 3. Tag the release (semver) on the export branch and push the tag
git tag v0.1.0 sdk-export
git push sdk-public v0.1.0
```

Within ~60 seconds Packagist picks up the new tag (via the GitHub App)
and publishes it. Consumers can immediately:

```bash
composer require uptimex/laravel-client:^0.1
```

### Versioning

We follow [Semantic Versioning](https://semver.org):

| Bump | Trigger |
|------|---------|
| **major** (`v1` → `v2`) | breaking changes to the public API (`Uptimex` facade, `Transport` interface, config keys) |
| **minor** (`v0.1` → `v0.2`) | new event types, new config options, new commands |
| **patch** (`v0.1.0` → `v0.1.1`) | bug fixes, internal refactors, doc updates |

Pre-1.0 we treat the minor as the breaking-change boundary, since `0.x`
is conventionally allowed to be unstable.

---

## What ships and what doesn't

`.gitattributes` controls the dist tarball — these paths are excluded
when consumers run `composer require`:

- `tests/`  — package's own test suite
- `phpunit.xml`  — test config
- `composer.lock`  — only the maintainer's lockfile, irrelevant to consumers
- `.github/`  — workflow files
- `.gitignore`, `.gitattributes`  — repo metadata

What *does* ship:

- `src/`  — runtime code
- `config/uptimex.php`  — publishable config
- `composer.json`, `LICENSE`, `README.md`

You can verify what'll be packaged by inspecting the tag locally:

```bash
git archive --format=tar v0.1.0 | tar -tv
```

---

## Helper script

A convenience wrapper lives at `scripts/publish-sdk.sh` (root of the
monorepo). It wraps the three release commands and prints the next step:

```bash
./scripts/publish-sdk.sh v0.1.0
```

The script:

1. Re-runs `git subtree split` from scratch (idempotent).
2. Force-updates `sdk-public/main`.
3. Tags the requested version and pushes the tag.

Use `--dry-run` to preview without pushing.

---

## Troubleshooting

### "Remote rejected" on push

The public repo is owned by Purposeeco. You need write access on GitHub
and either:
- An SSH key configured, OR
- A personal access token with `repo` scope set as `GITHUB_TOKEN`.

### Packagist still shows the old version

Tags can take up to 60s to sync. If still stale after a minute:
1. Visit the package's Packagist page.
2. Click **Update**.
3. Verify the GitHub App is still installed on the repo settings.

### Subtree split is slow

The command walks every commit in the monorepo's history. On a large
monorepo this can take ~30s. It's normal. Cached subtrees would speed
this up but aren't worth setting up at our scale.

### A consumer reports a missing file at runtime

If a runtime file got accidentally `export-ignore`'d in `.gitattributes`:

1. Edit `.gitattributes` to remove that path from the ignore list.
2. Cut a new patch release (`v0.1.0` → `v0.1.1`).
3. The consumer reruns `composer update uptimex/laravel-client`.

---

## Yanking a bad release

Don't delete tags — that breaks consumers' lockfiles.

If `v0.1.0` is broken:

1. Fix the bug on the monorepo's `main`.
2. Cut `v0.1.1` immediately.
3. (Optional) On Packagist, mark `v0.1.0` as **abandoned**.

Consumers who pinned `^0.1` will pick up `0.1.1` on their next
`composer update`. Consumers stuck on `0.1.0` see the abandoned
warning and know to upgrade.
