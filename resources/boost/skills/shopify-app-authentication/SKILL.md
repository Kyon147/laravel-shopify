---
name: shopify-app-authentication
description: OAuth, session tokens, and route protection for a Laravel Shopify app using kyon147/laravel-shopify.
---

## When to use

You are in a **host Laravel application** that depends on `kyon147/laravel-shopify` and you are configuring or debugging **authentication**: first install, OAuth redirect loop, embedded admin vs full-page auth, session token / App Bridge, or protecting **your** routes with package middleware.

## Key setup (in your app)

1. Publish config: `php artisan vendor:publish --tag=shopify-config` (adjust tag if your install docs differ).
2. Set env keys from the package README and **published** `config/shopify-app.php` — at minimum `SHOPIFY_API_KEY`, `SHOPIFY_API_SECRET`, `SHOPIFY_API_SCOPES` (maps to `api_scopes`), and URLs that match Shopify Partners / `APP_URL`.
3. Run package migrations (or publish `shopify-migrations` if you use `SHOPIFY_MANUAL_MIGRATIONS`).
4. Your **shop** model (often `User`) should implement `Osiset\ShopifyApp\Contracts\ShopModel` and use `Osiset\ShopifyApp\Traits\ShopModel` — see package `src/Contracts/ShopModel.php` and `src/Traits/ShopModel.php` as reference only.

## Middleware (attach in your routes)

The package registers route middleware aliases (see `ShopifyAppProvider::bootMiddlewares()`):

| Alias | Purpose |
| --- | --- |
| `verify.shopify` | Ensure the shop is authenticated for **your** protected routes. |
| `verify.scopes` | Re-check granted scopes when needed. |
| `auth.proxy` | App proxy requests from Shopify. |
| `auth.webhook` | Webhook HMAC verification (typically on webhook routes). |
| `billable` | Billing gate (see billing skill). |

`IframeProtection` is pushed onto the `web` middleware group by the package.

Example (conceptual): wrap **your** app routes in a group using `middleware(['verify.shopify'])` (and `verify.scopes` if you split concerns).

## How pieces fit together (reference)

- High-level auth flow: package `AuthController`, `Actions/InstallShop`, `Actions/AuthenticateShop` — **do not edit vendor**; override behavior via config, listeners, or **your** routes if the package documents extension points.
- SPA / session token: package views under `src/resources/views/auth/` — use or replace in **your** app as documented, not by patching the package.

## Do / Don’t

- **Do** keep `APP_URL`, Shopify app URLs, and redirect URLs aligned with Partners dashboard settings.
- **Do** use the published config keys the package defines; avoid inventing parallel “secret” env names.
- **Don’t** disable HMAC verification, session checks, or related middleware in config or custom middleware to bypass errors.
