<?php

namespace Osiset\ShopifyApp\Objects\Enums;

/**
 * API types for plans.
 *
 * Backing values are the names the package has always stored.
 */
enum PlanType: string
{
    case Recurring = 'RECURRING';
    case OneTime = 'ONETIME';

    /**
     * Create from a stored or API value, ignoring case.
     */
    public static function fromNative(string $name): self
    {
        return self::from(strtoupper($name));
    }
}
