# Laravel Shopify App

![Tests](https://github.com/osiset/laravel-shopify/workflows/Package%20Test/badge.svg?branch=master)
[![codecov](https://codecov.io/gh/osiset/laravel-shopify/branch/master/graph/badge.svg?token=qqUuLItqJj)](https://codecov.io/gh/osiset/laravel-shopify)
[![License](https://poser.pugx.org/osiset/laravel-shopify/license)](https://packagist.org/packages/osiset/laravel-shopify)

----

**[Closing]**

**Please read [this announcement](https://github.com/osiset/laravel-shopify/discussions/1276).**

@kyon147 is going to maintain a version which you can find here https://github.com/Kyon147/laravel-shopify 

----

A full-featured Laravel package for aiding in Shopify App development, similar to `shopify_app` for Rails. Works for Laravel 7 and up.

![Screenshot](https://github.com/osiset/laravel-shopify/raw/master/screenshot.png)
![Screenshot: Billable](https://github.com/osiset/laravel-shopify/raw/master/screenshot-billable.png)

## Table of Contents

__*__ *Wiki pages*

- [Goals](#goals)
- [Documentation](#documentation)
- [Installation](https://github.com/osiset/laravel-shopify/wiki/Installation)*
- [Route List](https://github.com/osiset/laravel-shopify/wiki/Route-List)*
- [Usage](https://github.com/osiset/laravel-shopify/wiki/Usage)*
- [Changelog](https://github.com/osiset/laravel-shopify/wiki/Changelog)*
- [Contributing Guide](https://github.com/osiset/laravel-shopify/blob/master/CONTRIBUTING.md)
- [LICENSE](#license)

For more information, tutorials, etc., please view the project's [wiki](https://github.com/osiset/laravel-shopify/wiki).

## Goals

- [x] Provide assistance in developing Shopify apps with Laravel
- [x] Integration with Shopify API (REST, async REST, GraphQL)
- [x] Authentication & installation for shops (both per-user and offline types)
- [x] Plan & billing integration for single, recurring, and usage-types
- [x] Tracking charges to a shop (recurring, single, usage, etc) with trial support
- [x] Auto install app webhooks and scripttags through background jobs
- [x] Provide basic AppBridge views
- [x] Handles and processes incoming webhooks
- [x] Handles and verifies incoming app proxy requests
- [x] Namespacing abilities to run multiple apps on the same database

## Documentation

For full resources on this package, see the [wiki](https://github.com/osiset/laravel-shopify/wiki).

For internal documentation, it is [available here](https://osiset.com/laravel-shopify/) from phpDocumentor.

## Issue or request?

If you have found a bug or would like to request a feature for discussion, please use the `ISSUE_TEMPLATE` in this repo when creating your issue. Any issue submitted without this template will be closed.

## License

This project is released under the MIT [license](https://github.com/osiset/laravel-shopify/blob/master/LICENSE).

## Misc

### Repository

#### Contributors

Contributions are always welcome! Contibutors are updated each release, pulled from Github. See `CONTRIBUTORS.txt`.

If you're looking to become a contributor, please see `CONTRIBUTING.md`.

#### Maintainers

Maintainers are users who manage the repository itself, whether it's managing the issues, assisting in releases, or helping with pull requests.

Currently this repository is maintained by:

- [@osiset](https://github.com/osiset)
- [@kyon147](https://github.com/kyon147)
- [@lucasmichot](https://github.com/lucasmichot)

Looking to become a maintainer? E-mail @osiset directly.

### Special Note

I develop this package in my spare time, with a busy family/work life like many of you! So, I would like to thank everyone who's helped me out from submitting PRs, to assisting on issues, and plain using the package (I hope its useful). Cheers.
