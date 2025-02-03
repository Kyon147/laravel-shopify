<?php

namespace Osiset\ShopifyApp\ApiKeyFinder;

use Illuminate\Support\Facades\Config;
use Osiset\ShopifyApp\Contracts\CurrentApiKeyFinderInterface;

class CurrentApiKeyFinder implements CurrentApiKeyFinderInterface
{
    public static function resolve(string $key, $shop = null): ?string
    {
        $fullKey = "shopify-app.{$key}";
        if (! $shop) {
            // No shop passed, return default
            return Config::get($fullKey);
        }

        // Clean the shop domain
        $shopDomain = $shop instanceof ShopDomainValue ? $shop->toNative() : $shop;
        $shopDomain = preg_replace('/[^A-Z0-9]/', '', strtoupper(explode('.', $shopDomain)[0]));

        // Try to get env defined for shop, fallback to config value
        return env(
            strtoupper($key) . "_" . $shopDomain,
            Config::get($fullKey)
        );
    }
}
