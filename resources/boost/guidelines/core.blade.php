## kyon147/laravel-shopify (host app)

- This Composer package adds **Shopify embedded / custom app** support to **your** Laravel app: OAuth, session handling, billing helpers, webhooks, and related tooling. After install: `php artisan vendor:publish --tag=shopify-config`, set documented `SHOPIFY_*` keys in `.env`, run migrations, and wire **your** shop/user model to the package contract + trait.
- **Agents:** use **published** `config/shopify-app.php` and documented env keys only. Do not weaken HMAC, session token, or webhook verification in app code to “fix” auth.
- **Where to work:** **your** `.env`, `config/shopify-app.php`, `routes/*.php`, models, and middleware registration. Package source under `vendor/kyon147/laravel-shopify` is **read-only reference** (e.g. default routes in `src/resources/routes/`, middleware in `src/Http/`).

### Skill routing (activate the matching skill)

| Task in the **host app** | Skill |
| --- | --- |
| OAuth, embedded auth, `verify.shopify`, session token / App Bridge, protecting routes | `shopify-app-authentication` |
| Subscriptions, charges, `billable` middleware, plan URLs | `shopify-app-billing` |
| Webhook topics, HMAC verification, uninstall / compliance jobs, queues | `shopify-app-webhooks` |
| PHPUnit / Pest / HTTP tests for **your** app using the package | `shopify-app-integration-testing` |
| Expiring offline tokens, refresh skew, migrations, `APP_KEY`, refresh failures | `shopify-app-offline-access-tokens` |
