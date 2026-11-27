<?php

namespace Osiset\ShopifyApp\Objects\Enums;

/**
 * API auth modes.
 *
 * Backing values are the names the package has always stored.
 */
enum AuthMode: string
{
    case Offline = 'OFFLINE';
    case PerUser = 'PERUSER';

    /**
     * Create from a stored or API value, ignoring case.
     */
    public static function fromNative(string $name): self
    {
        return self::from(strtoupper($name));
    }
}
