---
name: shopify-app-billing
description: Subscriptions, charges, and billable routes for a Laravel Shopify app using kyon147/laravel-shopify.
---

## When to use

You are in a **host Laravel application** with `kyon147/laravel-shopify` and you need **billing**: recurring or one-time charges, plan selection URLs, or gating **your** controllers until the shop has an active plan.

## Configuration (your published `shopify-app.php`)

Enable and tune billing via env + config keys such as:

- `SHOPIFY_BILLING_ENABLED` → `billing_enabled`
- `SHOPIFY_BILLING_FREEMIUM_ENABLED` → `billing_freemium_enabled`
- `SHOPIFY_BILLING_REDIRECT` → `billing_redirect` (where users land for the billing flow)

Confirm `route_names` for `billing`, `billing.process`, and `billing.usage_charge` if you override route names to avoid collisions with **your** app.

## Protecting routes

Apply the `billable` middleware (alias for `Osiset\ShopifyApp\Http\Middleware\Billable`) to **your** route groups or controllers that should only run for shops with a valid charge/plan per package rules.

Combine with `verify.shopify` when the route also requires an authenticated shop session.

## Extending billing in the app (don’t edit vendor)

- Package `BillingController` and `Traits/BillingController` show how billing endpoints are structured — **extend or wrap** from **your** `App\Http\Controllers` if you need custom UX, keeping the package’s config and charge flow intact.
- API calls for plans/charges typically go through the shop model’s integration with `apiHelper()` and related storage — reference `src/Services/ApiHelper.php` and models under `src/Storage/Models/` in vendor for behavior, implement app-specific logic in **your** namespaces.

## Do / Don’t

- **Do** test the full redirect loop (plan URL → Shopify approval → callback) in a dev store.
- **Don’t** bypass `billable` or charge checks in production routes for convenience.
