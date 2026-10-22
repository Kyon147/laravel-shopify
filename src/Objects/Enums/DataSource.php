<?php

namespace Osiset\ShopifyApp\Objects\Enums;

/**
 * Source of data for Shopify requests.
 *
 * Backing values match legacy Funeralzone {@see EnumTrait} {@see toNative()} (constant name strings).
 */
enum DataSource: string
{
    case INPUT = 'INPUT';
    case HEADER = 'HEADER';
    case REFERER = 'REFERER';

    /**
     * @deprecated Use {@see self::tryFrom()} or enum cases; kept for backward compatibility with ValueObject API.
     */
    public static function fromNative(string $name): self
    {
        return self::from(strtoupper($name));
    }
}
