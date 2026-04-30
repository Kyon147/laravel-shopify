# Laravel Shopify App

![Tests](https://github.com/kyon147/laravel-shopify/workflows/Package%20Test/badge.svg?branch=master)
[![codecov](https://codecov.io/gh/kyon147/laravel-shopify/branch/master/graph/badge.svg?token=qqUuLItqJj)](https://codecov.io/gh/kyon147/laravel-shopify)
[![License](https://poser.pugx.org/kyon147/laravel-shopify/license)](https://packagist.org/packages/osiset/laravel-shopify)

----

This is a maintained version of the wonderful but now deprecated original [Laravel Shopify App](https://github.com/gnikyt/laravel-shopify/). To keep things clean, this has been detached from the original.

----
To install this package run:
```
composer require kyon147/laravel-shopify
```
Publish the config file:
```
php artisan vendor:publish --tag=shopify-config
```
----

A full-featured Laravel package for aiding in Shopify App development, similar to `shopify_app` for Rails. Works for Laravel 8 and up.

![Screenshot](screenshot.png)
![Screenshot: Billable](screenshot-billable.png)

## Table of Contents

__*__ *Wiki pages*

- [Goals](#goals)
- [Documentation](#documentation)
- [Installation](../../wiki/Installation)*
- [Route List](../../wiki/Route-List)*
- [Usage](../../wiki/Usage)*
- [Changelog](../../wiki/Changelog)*
- [Contributing Guide](CONTRIBUTING.md)
- [LICENSE](#license)

For more information, tutorials, etc., please view the project's [wiki](../../wiki).

## Goals

- [ ] Per User Auth Working
- [ ] Better support for SPA apps using VueJS
- [ ] Getting "Blade" templates working better with Shopify's new auth process???

## Documentation

For full resources on this package, see the [wiki](../..//wiki).

### Expiring offline access tokens

[Shopify requires expiring offline access tokens](https://shopify.dev/changelog/expiring-offline-access-tokens-required-for-public-apps-april-1-2026) for **new public apps** created on or after April 1, 2026. This package supports them when enabled:

1. Run package migrations so your shops table includes `shopify_offline_refresh_token`, `shopify_offline_access_token_expires_at`, and `shopify_offline_refresh_token_expires_at`.
2. Set `SHOPIFY_EXPIRING_OFFLINE_TOKENS=true` in `.env` (see `expiring_offline_tokens` and `offline_access_token_refresh_skew_seconds` in `config/shopify-app.php`).
3. Keep `APP_KEY` stable: refresh tokens are stored encrypted with Laravel’s encrypter.

Authorization code exchange, session-token exchange, and `refresh_token` grants are handled inside this package (`Osiset\ShopifyApp\Services\ApiHelper` and `OfflineAccessTokenRefresher`), not via `gnikyt/basic-shopify-api` updates. A valid access token is refreshed automatically before `apiHelper()` builds the API session when the offline token is expired or within the configured skew.

If your `User` model overrides `$casts`, merge `datetime` casts for the two `*_expires_at` columns (the `ShopModel` trait uses `mergeCasts` when `initializeShopModel` runs).

Longer term, consider replacing or forking `gnikyt/basic-shopify-api` for REST/Graph traffic if you need an actively maintained HTTP client; expiring offline OAuth is already decoupled from that dependency.

## Issue or request?

If you have found a bug or would like to request a feature for discussion, please use the `ISSUE_TEMPLATE` in this repo when creating your issue. Any issue submitted without this template will be closed.

## License

This project is released under the MIT [license](LICENSE).

## Misc

### Repository

#### Contributors

Contributions are always welcome! Contibutors are updated each release, pulled from Github. See [`CONTRIBUTORS.txt`](CONTRIBUTORS.txt).

If you're looking to become a contributor, please see [`CONTRIBUTING.md`](CONTRIBUTING.md).

#### Maintainers

Maintainers are users who manage the repository itself, whether it's managing the issues, assisting in releases, or helping with pull requests.

Currently this repository is maintained by:

- [@kyon147](https://github.com/kyon147)
- ~[@gnikyt](https://github.com/gnikyt)~ Original author of the package. See [announcement](https://github.com/gnikyt/laravel-shopify/discussions/1276) for details.

Looking to become a maintainer? E-mail @kyon147 directly.

### Special Note

I develop this package in my spare time, with a busy family/work life like many of you! So, I would like to thank everyone who's helped me out from submitting PRs, to assisting on issues, and plain using the package (I hope its useful). Cheers.
