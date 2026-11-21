<?php

namespace Osiset\ShopifyApp\Objects\Enums;

/**
 * API types for charges.
 *
 * Backing values are the names the package has always stored.
 * There is no ONETIME case: {@see fromNative()} maps "ONETIME" to {@see self::CHARGE},
 * which the package has always stored for one-time charges.
 */
enum ChargeType: string
{
    case RECURRING = 'RECURRING';
    case CHARGE = 'CHARGE';
    case USAGE = 'USAGE';
    case CREDIT = 'CREDIT';

    /**
     * Create from a stored or API value, ignoring case.
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
