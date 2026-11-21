<?php

namespace Osiset\ShopifyApp\Objects\Enums;

/**
 * API charge status.
 *
 * Backing values are the names the package has always stored.
 */
enum ChargeStatus: string
{
    case Active = 'ACTIVE';
    case Accepted = 'ACCEPTED';
    case Declined = 'DECLINED';
    case Cancelled = 'CANCELLED';
    case Pending = 'PENDING';

    /**
     * Not approved by the merchant within two days of being created.
     */
    case Expired = 'EXPIRED';

    /**
     * On hold because the shop has not paid Shopify; it becomes active again once payments resume.
     */
    case Frozen = 'FROZEN';

    /**
     * Create from a stored or API value, ignoring case.
     */
    public static function fromNative(string $name): self
    {
        return self::from(strtoupper($name));
    }
}
