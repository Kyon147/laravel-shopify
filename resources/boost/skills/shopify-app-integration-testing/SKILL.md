---
name: shopify-app-integration-testing
description: Writing application-level tests for a Laravel Shopify app that uses kyon147/laravel-shopify.
---

## When to use

You are writing **PHPUnit**, **Pest**, or **HTTP** tests in **your** Laravel application (not in the package repo) that exercise routes or services integrated with `kyon147/laravel-shopify`.

## What to test

- **Your** routes behind `verify.shopify`, `billable`, or other package middleware: use Laravel’s HTTP testing helpers, acting as the authenticated shop user/model your app uses, and set session or headers the middleware expects per your app’s auth mode.
- **Your** controllers, jobs, and listeners that react to Shopify-related events or stored shop state.

## Faking Shopify and HTTP

- Fake HTTP to Shopify at **your application boundary** — e.g. `Http::fake()` for outgoing Admin API calls, or test doubles for services **you** own — instead of relying on JSON fixtures from the **package’s** internal test suite (those exist only for developing `laravel-shopify` itself).
- Keep tests deterministic: stub time if you assert token expiry windows.

## Common pitfalls

- **`APP_URL`** mismatch with route generation or redirect assertions in tests.
- **Shop domain / shop record** missing on the user model when middleware expects it.
- **`APP_KEY`** missing or changing between runs when encrypted casts or session encryption affect outcomes.

## Do / Don’t

- **Do** prefer testing **your** app’s public HTTP surface and job dispatch over reaching into vendor classes.
- **Don’t** copy package-internal fixture files into your app unless you are explicitly mirroring a contract you own; prefer fakes at the HTTP client or service level.
