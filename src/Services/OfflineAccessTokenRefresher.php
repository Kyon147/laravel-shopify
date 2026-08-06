<?php

namespace Osiset\ShopifyApp\Services;

use Gnikyt\BasicShopifyAPI\Session;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Osiset\ShopifyApp\Contracts\ApiHelper as IApiHelper;
use Osiset\ShopifyApp\Contracts\Commands\Shop as IShopCommand;
use Osiset\ShopifyApp\Contracts\ShopModel;
use Osiset\ShopifyApp\Exceptions\OAuthTokenRefreshException;
use Osiset\ShopifyApp\Objects\Values\AccessToken;
use Osiset\ShopifyApp\Util;

/**
 * Refreshes expiring offline access tokens before API use.
 */
class OfflineAccessTokenRefresher
{
    public function __construct(
        protected IApiHelper $apiHelper,
        protected IShopCommand $shopCommand
    ) {
    }

    /**
     * Refresh the shop's offline access token if it is expired or near expiry.
     *
     * @param ShopModel  $shop
     * @param int|null   $renewalDays Override the refresh-token renewal window in days (defaults to config)
     *
     * @throws OAuthTokenRefreshException
     *
     * @return void
     */
    public function refreshIfNeeded(ShopModel $shop, ?int $renewalDays = null): void
    {
        if (! Util::getShopifyConfig('expiring_offline_tokens', $shop)) {
            return;
        }

        if (! $shop->hasExpiringOfflineAccess()) {
            return;
        }

        if (! $this->offlineTokenNeedsRefresh($shop, $renewalDays)) {
            return;
        }

        Cache::lock('shopify-offline-token:'.$shop->getId()->toNative(), 30)->block(10, function () use ($shop, $renewalDays) {
            $shop->refresh();

            if (! $this->offlineTokenNeedsRefresh($shop, $renewalDays)) {
                return;
            }

            $encrypted = $shop->shopify_offline_refresh_token ?? '';
            if ($encrypted === '') {
                return;
            }

            try {
                $refreshPlain = Crypt::decryptString($encrypted);
            } catch (\Throwable $e) {
                throw new OAuthTokenRefreshException(
                    'Unable to decrypt offline refresh token.',
                    0,
                    $e
                );
            }

            $session = new Session(
                $shop->getDomain()->toNative(),
                $shop->getAccessToken()->toNative()
            );

            $data = $this->apiHelper->make($session)->refreshOfflineAccessToken($refreshPlain);

            $expiresIn = (int) ($data['expires_in'] ?? 0);
            $refreshExpiresIn = (int) ($data['refresh_token_expires_in'] ?? 0);
            $newRefresh = $data['refresh_token'] ?? null;

            if ($expiresIn <= 0 || $refreshExpiresIn <= 0 || empty($newRefresh)) {
                throw new OAuthTokenRefreshException('Invalid token refresh response from Shopify.');
            }

            $this->shopCommand->setAccessToken(
                $shop->getId(),
                AccessToken::fromNative($data['access_token']),
                $newRefresh,
                Carbon::now()->addSeconds($expiresIn),
                Carbon::now()->addSeconds($refreshExpiresIn)
            );

            $shop->refresh();
        });
    }

    /**
     * Whether the shop's offline access token is expired or within the refresh skew window.
     *
     * @param ShopModel $shop
     *
     * @return bool
     */
    public function offlineAccessTokenNeedsRefresh(ShopModel $shop): bool
    {
        if (! Util::getShopifyConfig('expiring_offline_tokens', $shop)) {
            return false;
        }

        if (! $shop->hasExpiringOfflineAccess()) {
            return false;
        }

        $expiresAt = $shop->shopify_offline_access_token_expires_at ?? null;
        if ($expiresAt === null) {
            return false;
        }

        $skew = (int) Util::getShopifyConfig('offline_access_token_refresh_skew_seconds', $shop);
        $expires = $expiresAt instanceof Carbon ? $expiresAt : Carbon::parse((string) $expiresAt);
        $threshold = $expires->copy()->subSeconds($skew);

        return Carbon::now()->greaterThanOrEqualTo($threshold);
    }

    /**
     * Whether the shop's offline refresh token expires within the renewal window.
     *
     * Already-expired refresh tokens return false because they cannot be renewed
     * via the refresh-token grant.
     *
     * @param ShopModel  $shop
     * @param int|null   $renewalDays Override the renewal window in days (defaults to config)
     *
     * @return bool
     */
    public function offlineRefreshTokenNeedsRenewal(ShopModel $shop, ?int $renewalDays = null): bool
    {
        if (! Util::getShopifyConfig('expiring_offline_tokens', $shop)) {
            return false;
        }

        if (! $shop->hasExpiringOfflineAccess()) {
            return false;
        }

        $expiresAt = $shop->shopify_offline_refresh_token_expires_at ?? null;
        if ($expiresAt === null) {
            return false;
        }

        $days = $renewalDays ?? (int) Util::getShopifyConfig('offline_refresh_token_renewal_days', $shop);
        $expires = $expiresAt instanceof Carbon ? $expiresAt : Carbon::parse((string) $expiresAt);
        $now = Carbon::now();

        // Expired refresh tokens cannot be renewed via the refresh-token grant.
        if ($expires->lessThanOrEqualTo($now)) {
            return false;
        }

        $threshold = $now->copy()->addDays($days);

        return $threshold->greaterThanOrEqualTo($expires);
    }

    /**
     * Whether the shop's offline access or refresh token should be renewed.
     *
     * @param ShopModel  $shop
     * @param int|null   $renewalDays Override the refresh-token renewal window in days (defaults to config)
     *
     * @return bool
     */
    protected function offlineTokenNeedsRefresh(ShopModel $shop, ?int $renewalDays = null): bool
    {
        return $this->offlineAccessTokenNeedsRefresh($shop)
            || $this->offlineRefreshTokenNeedsRenewal($shop, $renewalDays);
    }
}
