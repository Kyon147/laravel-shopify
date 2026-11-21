<?php

namespace Osiset\ShopifyApp\Objects\Enums;

/**
 * Which Shopify session-token shape is in use.
 *
 * Backing values are the integers the package has always stored.
 */
enum SessionTokenSource: int
{
    case APP = 0;
    case CHECKOUT_EXTENSION = 1;

    /**
     * Create from a stored value.
     */
    public static function fromNative(int $value): self
    {
        return self::from($value);
    }
}
