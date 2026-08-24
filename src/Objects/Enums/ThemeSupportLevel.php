<?php

namespace Osiset\ShopifyApp\Objects\Enums;

/**
 * Online Store 2.0 theme support level.
 *
 * Integer backing matches legacy public int constants on the previous class.
 * There is no enum case for legacy {@see NONE} (null); represent “unknown / skipped” with null in application code.
 */
enum ThemeSupportLevel: int
{
    case FULL = 0;
    case PARTIAL = 1;
    case UNSUPPORTED = 2;

    /**
     * @deprecated Use {@see self::tryFrom()} or enum cases; kept for backward compatibility with ValueObject API.
     */
    public static function fromNative(int $value): self
    {
        return self::from($value);
    }
}
