<?php

namespace Osiset\ShopifyApp\Support;

use Illuminate\Support\Facades\Crypt;
use Osiset\ShopifyApp\Contracts\ShopModel;
use Osiset\ShopifyApp\Util;
use Throwable;

/**
 * Shops whose encrypted refresh token stores a sentinel (not a Shopify refresh token).
 */
final class ApplicationOnlyOfflineAccess
{
    public static function refreshTokenSentinel(): string
    {
        return (string) Util::getShopifyConfig('application_only_refresh_sentinel');
    }

    public static function shopUses(ShopModel $shop): bool
    {
        $encrypted = $shop->shopify_offline_refresh_token ?? '';

        if ($encrypted === '') {
            return false;
        }

        try {
            $plain = Crypt::decryptString($encrypted);
        } catch (Throwable $e) {
            return false;
        }

        return hash_equals(self::refreshTokenSentinel(), $plain);
    }

    /**
     * Whether the shop should be considered by proactive refresh-token renewal (Artisan / jobs).
     *
     * Application-only shops store a sentinel in the refresh column, not a Shopify refresh token.
     * They renew via client assertion when access expires; the scheduled command targets real
     * refresh-token expiry only. Sentinel values are encrypted with a random IV, so this cannot
     * be expressed as a SQL predicate — callers filter after loading candidate rows.
     */
    public static function shopEligibleForScheduledRefreshTokenRenewal(ShopModel $shop): bool
    {
        return self::shopUses($shop) === false;
    }
}
