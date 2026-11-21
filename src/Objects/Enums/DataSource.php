<?php

namespace Osiset\ShopifyApp\Objects\Enums;

/**
 * Source of data for Shopify requests.
 *
 * Backing values are the names the package has always stored.
 */
enum DataSource: string
{
    case INPUT = 'INPUT';
    case HEADER = 'HEADER';
    case REFERER = 'REFERER';

    /**
     * Create from a stored or API value, ignoring case.
     */
    public static function fromNative(string $name): self
    {
        return self::from(strtoupper($name));
    }
}
