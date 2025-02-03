<?php

namespace Osiset\ShopifyApp\Contracts;

interface CurrentApiKeyFinderInterface
{
    public static function resolve(string $key, $shop = null): ?string;
}
