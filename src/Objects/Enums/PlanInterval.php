<?php

namespace Osiset\ShopifyApp\Objects\Enums;

/**
 * Plan interval with annual support.
 *
 * Backing values are the names the package has always stored.
 */
enum PlanInterval: string
{
    case EVERY_30_DAYS = 'EVERY_30_DAYS';
    case ANNUAL = 'ANNUAL';

    /**
     * Create from a stored or API value, ignoring case.
     */
    public static function fromNative(string $name): self
    {
        return self::from(strtoupper($name));
    }
}
