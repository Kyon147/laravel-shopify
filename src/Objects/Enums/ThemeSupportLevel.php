<?php

namespace Osiset\ShopifyApp\Objects\Enums;

/**
 * Online Store 2.0 theme support level.
 *
 * Backing values are the integers the package has always stored.
 * There is no case for "none": an unknown or skipped check is null.
 */
enum ThemeSupportLevel: int
{
    case FULL = 0;
    case PARTIAL = 1;
    case UNSUPPORTED = 2;

    /**
     * Create from a stored value.
     */
    public static function fromNative(int $value): self
    {
        return self::from($value);
    }
}
