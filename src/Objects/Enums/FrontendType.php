<?php

namespace Osiset\ShopifyApp\Objects\Enums;

/**
 * Frontend type (MPA vs SPA).
 *
 * Backing values match legacy Funeralzone {@see EnumTrait} {@see toNative()} (constant name strings).
 */
enum FrontendType: string
{
    case MPA = 'MPA';
    case SPA = 'SPA';

    /**
     * @deprecated Use {@see self::tryFrom()} or enum cases; kept for backward compatibility with ValueObject API.
     */
    public static function fromNative(string $name): self
    {
        return self::from(strtoupper($name));
    }
}
