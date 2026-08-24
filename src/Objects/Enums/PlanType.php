<?php

namespace Osiset\ShopifyApp\Objects\Enums;

/**
 * API types for plans.
 *
 * Backing values match legacy Funeralzone {@see EnumTrait} {@see toNative()} (constant name strings).
 */
enum PlanType: string
{
    case RECURRING = 'RECURRING';
    case ONETIME = 'ONETIME';

    /**
     * @deprecated Use {@see self::tryFrom()} or enum cases; kept for backward compatibility with ValueObject API.
     */
    public static function fromNative(string $name): self
    {
        return self::from(strtoupper($name));
    }
}
