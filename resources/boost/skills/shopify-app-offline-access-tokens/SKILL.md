---
name: shopify-app-offline-access-tokens
description: Expiring offline access tokens, migrations, and refresh behavior for kyon147/laravel-shopify in a host Laravel app.
---

## When to use

You are enabling or operating **Shopify expiring offline access tokens** in **your** Laravel app: env toggles, database columns, model casts, production refresh failures, or API calls that should trigger transparent refresh.

## Configuration (your published `shopify-app.php`)

- `SHOPIFY_EXPIRING_OFFLINE_TOKENS` → `expiring_offline_tokens` — when `true`, new offline exchanges use refresh-token rotation per Shopify’s model.
- `SHOPIFY_OFFLINE_ACCESS_TOKEN_REFRESH_SKEW` → `offline_access_token_refresh_skew_seconds` — refresh this many seconds **before** access token expiry.

Read the package README for policy notes (e.g. public apps after Shopify’s cutoff dates).

## Database (your migrated schema)

Run package migrations so the shop table (from `Osiset\ShopifyApp\Util::getShopsTable()`, often `users`) includes:

- `shopify_offline_refresh_token`
- `shopify_offline_access_token_expires_at`
- `shopify_offline_refresh_token_expires_at`

If you override `$casts` on **your** shop model, merge with the package trait’s casts rather than dropping encrypted/datetime casts the package expects.

## Runtime behavior (reference)

- Token refresh is coordinated through `ApiHelper` and `OfflineAccessTokenRefresher` in the package — you normally do not call these directly from app code; ensure shops use `apiHelper()` (or equivalent documented entry points) so refresh runs when needed.
- Refresh failures throw `Osiset\ShopifyApp\Exceptions\OAuthTokenRefreshException` — handle or log in **your** exception reporting so ops can re-authenticate affected shops.

## Operational notes

- Keep **`APP_KEY` stable** in each environment; encrypted column values depend on it.
- Plan for **re-install or re-auth** when refresh tokens are revoked or invalid per Shopify.

## Do / Don’t

- **Do** enable `expiring_offline_tokens` and run migrations **before** assuming refresh metadata exists for legacy rows.
- **Don’t** store plaintext tokens in custom columns outside what the package supports without a documented security review.
