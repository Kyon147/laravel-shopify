---
name: shopify-app-webhooks
description: Registering and handling Shopify webhooks in a Laravel app using kyon147/laravel-shopify.
---

## When to use

You are in a **host Laravel application** with `kyon147/laravel-shopify` and you are configuring **webhooks**: topics and URLs, uninstall / compliance / GDPR flows, queue workers, or custom handlers alongside the package’s default webhook endpoint.

## Declaring webhooks (your published config)

In **published** `config/shopify-app.php`, the `webhooks` array defines GraphQL-style `topic` values and `address` URLs Shopify should call. You can map entries to a custom job class using a `class` key (see commented examples in the package’s default `src/resources/config/shopify-app.php`).

Ensure `address` values are reachable from the internet (ngrok / cloud tunnel in local dev) and match your app’s routes.

## Verification and dispatch (package behavior)

- Incoming webhooks should hit routes protected with `auth.webhook` middleware (`Osiset\ShopifyApp\Http\Middleware\AuthWebhook`) so HMAC verification runs before your logic executes.
- Package `WebhookController` + `Traits/WebhookController` show how payloads are turned into jobs — use as **read-only reference** when adding **your** job classes mapped in config.

## Queues and jobs (your app)

- Configure `queue.php` and workers so webhook jobs actually run in production.
- Package config includes `job_queues` / `job_connections` entries (e.g. `webhooks`) — set `WEBHOOKS_JOB_QUEUE` / `WEBHOOKS_JOB_CONNECTION` in `.env` if you isolate webhook traffic.
- Listen for package events in **your** `config/shopify-app.php` `listen` array (e.g. `AppUninstalledEvent`) and dispatch **your** listeners or jobs for cleanup.

## Do / Don’t

- **Do** register mandatory compliance webhooks required by Shopify for your app type.
- **Don’t** expose webhook URLs without HMAC verification or skip `auth.webhook` on routes that accept Shopify payloads.
