<?php

namespace Osiset\ShopifyApp\Objects\Enums;

/**
 * Charge interval with annual support.
 *
 * Backing values match legacy Funeralzone {@see EnumTrait} {@see toNative()} (constant name strings).
 */
enum ChargeInterval: string
{
    case EVERY_30_DAYS = 'EVERY_30_DAYS';
    case ANNUAL = 'ANNUAL';

    /**
     * @deprecated Use {@see self::tryFrom()} or enum cases; kept for backward compatibility with ValueObject API.
     */
    public static function fromNative(string $name): self
    {
        return self::from(strtoupper($name));
    }
}
