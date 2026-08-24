<?php

namespace Osiset\ShopifyApp\Objects\Enums;

/**
 * API auth modes.
 *
 * Backing values match legacy Funeralzone {@see EnumTrait} {@see toNative()} (constant name strings).
 */
enum AuthMode: string
{
    case OFFLINE = 'OFFLINE';
    case PERUSER = 'PERUSER';

    /**
     * @deprecated Use {@see self::tryFrom()} or enum cases; kept for backward compatibility with ValueObject API.
     */
    public static function fromNative(string $name): self
    {
        return self::from(strtoupper($name));
    }
}
