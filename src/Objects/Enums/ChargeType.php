<?php

namespace Osiset\ShopifyApp\Objects\Enums;

/**
 * API types for charges.
 *
 * Backing values match legacy Funeralzone {@see EnumTrait} {@see toNative()} (constant name strings).
 * {@see self::ONETIME} is not a separate case: use {@see self::CHARGE} (same int as legacy); accept "ONETIME" in {@see fromNative()}.
 */
enum ChargeType: string
{
    case RECURRING = 'RECURRING';
    case CHARGE = 'CHARGE';
    case USAGE = 'USAGE';
    case CREDIT = 'CREDIT';

    /**
     * @deprecated Use {@see self::tryFrom()} or enum cases; kept for backward compatibility with ValueObject API.
     */
    public static function fromNative(string $name): self
    {
        $upper = strtoupper($name);

        return match ($upper) {
            'ONETIME' => self::CHARGE,
            default => self::from($upper),
        };
    }
}
