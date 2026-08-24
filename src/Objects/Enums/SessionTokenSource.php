<?php

namespace Osiset\ShopifyApp\Objects\Enums;

/**
 * Which Shopify session-token shape is in use.
 *
 * Integer backing matches legacy public int constants on the previous class.
 */
enum SessionTokenSource: int
{
    case APP = 0;
    case CHECKOUT_EXTENSION = 1;

    /**
     * @deprecated Use {@see self::tryFrom()} or enum cases; kept for backward compatibility with ValueObject API.
     */
    public static function fromNative(int $value): self
    {
        return self::from($value);
    }
}
