<?php

namespace Osiset\ShopifyApp\Objects\Enums;

/**
 * API call method types.
 *
 * Backing values are the names the package has always stored.
 */
enum ApiMethod: string
{
    case Get = 'GET';
    case Post = 'POST';
    case Put = 'PUT';
    case Delete = 'DELETE';

    /**
     * Create from a stored or API value, ignoring case.
     */
    public static function fromNative(string $name): self
    {
        return self::from(strtoupper($name));
    }
}
