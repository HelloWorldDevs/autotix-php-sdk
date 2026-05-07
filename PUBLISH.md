# Publishing `autotix/php-sdk` to Packagist

The Drupal, Laravel, and WordPress modules currently consume the SDK via
a Composer **path repository** (configured in each module's
`composer.json`). That works for in-monorepo development, but downstream
consumers — Drupal sites, Laravel apps, WordPress installers — need the
SDK on Packagist to be able to `composer require autotix/php-sdk`.

This is intentionally a manual one-time setup followed by tagged
releases.

## One-time setup

1. Create a public mirror repository for the SDK only (Packagist requires
   a top-level `composer.json` in the repo root). Easiest: a sibling
   `autotix-php-sdk` repo on GitHub, populated by a script that copies
   `packages/php-sdk/` whenever we tag a release.
2. Submit it on https://packagist.org → Submit Package, paste the GitHub
   URL.
3. In the GitHub repo settings → Webhooks, add Packagist's webhook so
   future tags auto-sync.

## Cutting a release

1. Bump `version` in `packages/php-sdk/composer.json`.
2. Update `CHANGELOG.md` (TODO: create one when first non-trivial change
   ships).
3. Run any tests:
   ```bash
   cd packages/php-sdk
   composer install
   vendor/bin/phpunit
   ```
4. From repo root, copy the SDK into the mirror repo and tag:
   ```bash
   ./packages/php-sdk/bin/release.sh 0.2.0   # script TBD; for now do it manually
   ```
   Or, manually:
   ```bash
   git tag autotix-php-sdk-v0.2.0
   git push origin autotix-php-sdk-v0.2.0
   # in the mirror repo:
   git tag v0.2.0 && git push --tags
   ```
5. Packagist's webhook syncs the new tag within seconds. Verify at
   https://packagist.org/packages/autotix/php-sdk

## Once published

Each framework module can drop its `repositories` block and just use:

```jsonc
"require": {
  "autotix/php-sdk": "^0.2"
}
```

Drupal sites running Composer pull it transparently. Laravel apps too.
WordPress plugin's `bin/build-zip.sh` resolves it during `composer
install --no-dev` before the scoping step, so the released zip embeds
the published version.

## Why not just publish from the monorepo?

Packagist requires the package to live at the **root** of a git repo, or
be installable via a subtree split. Subtree splits work but they're
fiddly to keep in sync (every commit to `packages/php-sdk/` needs a
matching push to the mirror). The simpler path is one tag-driven copy
script — overhead is low because SDK releases will be infrequent.
