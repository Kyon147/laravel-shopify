---
name: shopify-app-offline-access-tokens
description: Expiring offline access tokens, migrations, and refresh behavior for kyon147/laravel-shopify in a host Laravel app.
---

## When to use

You are enabling or operating **Shopify expiring offline access tokens** in **your** Laravel app: env toggles, database columns, model casts, production refresh failures, or API calls that should trigger transparent refresh.

## Configuration (your published `shopify-app.php`)

- `SHOPIFY_EXPIRING_OFFLINE_TOKENS` → `expiring_offline_tokens` — when `true`, new offline exchanges use refresh-token rotation per Shopify’s model.
- `SHOPIFY_AUTO_MIGRATE_LEGACY` → `auto_migrate_legacy` — when `true` (default), legacy shops are migrated on-the-fly before the first `apiHelper()` call; failures fail open (logged warning, legacy token kept).
- `SHOPIFY_REFRESH_OFFLINE_TOKEN_BEFORE_API_CALL` → `refresh_offline_token_before_api_call` — when `true`, each `api()` / `apiHelper()` call re-checks token expiry and rebuilds the cached client if within the refresh skew (for long-running jobs).
- `SHOPIFY_OFFLINE_ACCESS_TOKEN_REFRESH_SKEW` → `offline_access_token_refresh_skew_seconds` — refresh this many seconds **before** access token expiry.
- `SHOPIFY_OFFLINE_REFRESH_TOKEN_RENEWAL_DAYS` → `offline_refresh_token_renewal_days` — shops whose refresh token expires within this many days are queued for renewal by the refresh CLI (default `14`).
- `SHOPIFY_MIGRATE_OFFLINE_TOKENS_JOB_QUEUE` / `SHOPIFY_MIGRATE_OFFLINE_TOKENS_JOB_CONNECTION` → `job_queues.migrate_expiring_offline_tokens` / `job_connections.migrate_expiring_offline_tokens` — queue targeting for the migrate Artisan command (overridable with `--queue=` / `--connection=`).
- `SHOPIFY_REFRESH_OFFLINE_TOKENS_JOB_QUEUE` / `SHOPIFY_REFRESH_OFFLINE_TOKENS_JOB_CONNECTION` → `job_queues.refresh_expiring_offline_tokens` / `job_connections.refresh_expiring_offline_tokens` — same for the refresh Artisan command.

Read the package README for policy notes (e.g. public apps after Shopify’s cutoff dates).

## Database (your migrated schema)

Run package migrations so the shop table (from `Osiset\ShopifyApp\Util::getShopsTable()`, often `users`) includes:

- `shopify_offline_refresh_token`
- `shopify_offline_access_token_expires_at`
- `shopify_offline_refresh_token_expires_at`

If you override `$casts` on **your** shop model, merge with the package trait’s casts rather than dropping encrypted/datetime casts the package expects.

## Runtime behavior (reference)

- Token refresh is coordinated through `ApiHelper` and `OfflineAccessTokenRefresher` in the package — you normally do not call these directly from app code; ensure shops use `apiHelper()` (or equivalent documented entry points) so refresh runs when needed.
- The API client is **memoized** on the shop model. Long-running jobs that reuse one instance across token expiry must either enable `refresh_offline_token_before_api_call` or call `$shop->refreshOfflineAccessTokenIfNeeded()` / `$shop->resetApiClient()` before subsequent API calls.
- Shop helpers: `offlineAccessTokenIsFresh()`, `refreshOfflineAccessTokenIfNeeded()`, `resetApiClient()`.
- Refresh failures throw `Osiset\ShopifyApp\Exceptions\OAuthTokenRefreshException` — handle or log in **your** exception reporting so ops can re-authenticate affected shops.

## Migrating existing shops (optional)

Enabling the flag does **not** upgrade shops that only have a legacy non-expiring token. Use one of:

- **Passive (default):** `auto_migrate_legacy` — first `apiHelper()` call runs Step 4 exchange; failures are logged and the legacy token is used (no downtime).
- **`Osiset\ShopifyApp\Messaging\Jobs\MigrateShopTokenJob`** — dispatched by the Artisan command or your own scheduler; one shop per job.
- **`Osiset\ShopifyApp\Actions\MigrateShopToExpiringOfflineAccessToken`** — invoke per shop from your code; inspect `migrated`, `skipped`, `reason`, `error` in the returned array.
- **`ApiHelper::exchangeNonExpiringOfflineTokenForExpiring($shopDomain, $currentToken)`** — [Shopify Step 4](https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens/offline-access-tokens#step-4-migrate-existing-tokens) token exchange; then persist via `ShopCommand::setAccessToken` with refresh + expiry fields.
- **`php artisan shopify-app:migrate-expiring-offline-tokens`** — optional CLI (`--dry-run`, `--shop=`, `--queue=`, `--connection=`). Chunks shops and dispatches queue jobs (Vapor/serverless-safe). Defaults to the app queue; set `SHOPIFY_MIGRATE_OFFLINE_TOKENS_JOB_QUEUE` / `SHOPIFY_MIGRATE_OFFLINE_TOKENS_JOB_CONNECTION` or use CLI overrides for a dedicated worker. Success revokes the old offline token (one-way).

Alternative: merchant re-auth (OAuth or session token exchange) also acquires expiring tokens when the flag is on.

## Proactive renewal for dormant shops (optional)

Refresh tokens expire after ~90 days. Shops with no API activity can lapse if nothing triggers a refresh.

- **`Osiset\ShopifyApp\Messaging\Jobs\RefreshShopOfflineTokenJob`** — dispatched by the refresh Artisan command; one shop per job.
- **`php artisan shopify-app:refresh-expiring-offline-tokens`** — optional CLI (`--dry-run`, `--shop=`, `--days=`, `--queue=`, `--connection=`). Queues renewal for shops whose refresh token expires within `offline_refresh_token_renewal_days` (default 14). Defaults to the app queue; set `SHOPIFY_REFRESH_OFFLINE_TOKENS_JOB_QUEUE` / `SHOPIFY_REFRESH_OFFLINE_TOKENS_JOB_CONNECTION` or use CLI overrides for a dedicated worker.
- **Scheduler:** the package does **not** auto-register a schedule. In **your** app:

```php
Schedule::command('shopify-app:refresh-expiring-offline-tokens')->daily();
```

Active shops with regular webhooks or API calls are refreshed automatically and usually do not need this.

## Operational notes

- Keep **`APP_KEY` stable** in each environment; encrypted column values depend on it.
- Plan for **re-install or re-auth** when refresh tokens are revoked or invalid per Shopify.

## Do / Don’t

- **Do** enable `expiring_offline_tokens` and run migrations **before** assuming refresh metadata exists for legacy rows.
- **Don’t** store plaintext tokens in custom columns outside what the package supports without a documented security review.
