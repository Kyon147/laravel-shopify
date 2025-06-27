<?php

namespace Osiset\ShopifyApp\ApiKeyFinder;

use Illuminate\Support\Facades\Config;
use Osiset\ShopifyApp\Contracts\CurrentApiKeyFinderInterface;
use Osiset\ShopifyApp\Objects\Values\NullableShopDomain;

class CurrentApiKeyFinder implements CurrentApiKeyFinderInterface
{
    private const SUPPORTED_KEYS = [
        'api_key',
        'api_secret'
    ];

    public static function resolve(string $key, $shop = null): ?string
    {
        $fullKey = "shopify-app.{$key}";
        if (! $shop || ! in_array($key, self::SUPPORTED_KEYS)) {
            // No shop passed, return default
            return Config::get($fullKey);
        }

        $shopDomain = $shop instanceof NullableShopDomain ? $shop->toNative() : $shop;
        $shopDomain = explode('.', $shopDomain)[0];
        $searchKey = "shopify-app.config_api_shop_keys.{$key}_{$shopDomain}";

        if (! Config::has($searchKey)) {
            return Config::get($fullKey);
        }

        return Config::get($searchKey);
    }
}