<?php

namespace Osiset\ShopifyApp\Objects\Enums;

/**
 * API types for charges.
 *
 * Backing values are the names the package has always stored.
 * There is no ONETIME case: {@see fromNative()} maps "ONETIME" to {@see self::Charge},
 * which the package has always stored for one-time charges.
 */
enum ChargeType: string
{
    case Recurring = 'RECURRING';
    case Charge = 'CHARGE';
    case Usage = 'USAGE';
    case Credit = 'CREDIT';

    /**
     * Create from a stored or API value, ignoring case.
     */
    public static function fromNative(string $name): self
    {
        $upper = strtoupper($name);

        return match ($upper) {
            'ONETIME' => self::Charge,
            default => self::from($upper),
        };
    }
}
