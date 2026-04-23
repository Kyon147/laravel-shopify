<?php

namespace Osiset\ShopifyApp\Traits;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Osiset\ShopifyApp\Contracts\ApiHelper as IApiHelper;
use Osiset\ShopifyApp\Objects\Values\AccessToken;

/**
 * Handles automatic refresh of Shopify expiring offline access tokens.
 *
 * As of December 2025, Shopify deprecated non-expiring offline tokens.
 * Every access token now expires in 1 hour and must be refreshed using
 * a 90-day one-time-use refresh token.
 *
 * Usage: Add `use RefreshesShopifyToken;` to your shop User model alongside
 * `use ShopModel;`. The `api()` method in ShopModel is overridden here so
 * every `$shop->api()->...` call is automatically protected — you never need
 * to call `ensureValidAccessToken()` manually.
 *
 * Required model columns (added by the package migration):
 *   - refresh_token            (text, nullable)
 *   - token_expires_at         (timestamp, nullable)
 *   - refresh_token_expires_at (timestamp, nullable)
 *
 * Required model casts:
 *   - 'token_expires_at'         => 'datetime'
 *   - 'refresh_token_expires_at' => 'datetime'
 */
trait RefreshesShopifyToken
{
    /**
     * Ensure the shop has a valid (non-expired) access token before an API call.
     *
     * Returns true if the token is valid or was successfully refreshed.
     * Returns false if a refresh was needed but could not be completed —
     * in which case the merchant must re-open the app to trigger a fresh OAuth.
     *
     * A cache lock prevents concurrent jobs from burning the one-time-use
     * refresh token simultaneously.
     *
     * @return bool
     */
    public function ensureValidAccessToken(): bool
    {
        // No expiry recorded = legacy non-expiring token (pre-Dec 2025 installs).
        // These will eventually fail when Shopify rejects them; the merchant
        // re-opening the app will trigger fresh OAuth and populate the columns.
        if ($this->token_expires_at === null) {
            return true;
        }

        // Token is still valid for at least 5 minutes — nothing to do.
        if ($this->token_expires_at->gt(Carbon::now()->addMinutes(5))) {
            return true;
        }

        // Token is expiring soon or already expired — attempt refresh.
        if (empty($this->refresh_token)) {
            Log::error('[Shopify] Access token expired but no refresh token stored.', [
                'shop' => $this->name ?? $this->id,
            ]);
            return false;
        }

        if ($this->refresh_token_expires_at !== null && $this->refresh_token_expires_at->isPast()) {
            Log::error('[Shopify] Refresh token has expired. Merchant must re-open the app.', [
                'shop'                     => $this->name ?? $this->id,
                'refresh_token_expires_at' => $this->refresh_token_expires_at->toIso8601String(),
            ]);
            return false;
        }

        // Use a cache lock so only one process refreshes at a time.
        // Other concurrent processes will wait up to 5 seconds, then read
        // the freshly saved token from the database.
        $lockKey = 'shopify-token-refresh-' . $this->id;

        return Cache::lock($lockKey, 10)->block(5, function () {
            // Re-read from DB inside the lock — another process may have already
            // refreshed while we were waiting.
            $this->refresh();

            if ($this->token_expires_at !== null && $this->token_expires_at->gt(Carbon::now()->addMinutes(5))) {
                // Token was refreshed by another process while we waited.
                $this->apiHelper = null;
                return true;
            }

            try {
                $freshData = $this->apiHelper()->refreshAccessToken($this->refresh_token);

                $this->forceFill([
                    'password'                 => $freshData['access_token'],
                    'refresh_token'            => $freshData['refresh_token'],
                    'token_expires_at'         => Carbon::now()->addSeconds((int) $freshData['expires_in']),
                    'refresh_token_expires_at' => Carbon::now()->addSeconds((int) $freshData['refresh_token_expires_in']),
                ])->save();

                // Reset the apiHelper so the next api() call builds a new
                // session with the fresh access token.
                $this->apiHelper = null;

                Log::info('[Shopify] Access token refreshed successfully.', [
                    'shop'             => $this->name ?? $this->id,
                    'token_expires_at' => $this->token_expires_at->toIso8601String(),
                ]);

                return true;
            } catch (\Throwable $e) {
                Log::error('[Shopify] Failed to refresh access token.', [
                    'shop'  => $this->name ?? $this->id,
                    'error' => $e->getMessage(),
                ]);
                return false;
            }
        });
    }

    /**
     * Override ShopModel::api() to transparently ensure a valid token before
     * returning the API instance. Every $shop->api()->... call is protected.
     *
     * @return \Gnikyt\BasicShopifyAPI\BasicShopifyAPI
     */
    public function api(): \Gnikyt\BasicShopifyAPI\BasicShopifyAPI
    {
        $this->ensureValidAccessToken();

        if ($this->apiHelper === null) {
            $this->apiHelper();
        }

        return $this->apiHelper->getApi();
    }
}
