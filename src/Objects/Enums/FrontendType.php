<?php

namespace Osiset\ShopifyApp\Objects\Enums;

/**
 * Frontend type (MPA vs SPA).
 *
 * Backing values are the names the package has always stored.
 */
enum FrontendType: string
{
    case Mpa = 'MPA';
    case Spa = 'SPA';

    /**
     * Create from a stored or API value, ignoring case.
     */
    public static function fromNative(string $name): self
    {
        return self::from(strtoupper($name));
    }
}
