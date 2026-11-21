<?php

namespace Osiset\ShopifyApp\Objects\Enums;

/**
 * API charge status.
 *
 * Backing values are the names the package has always stored.
 */
enum ChargeStatus: string
{
    case ACTIVE = 'ACTIVE';
    case ACCEPTED = 'ACCEPTED';
    case DECLINED = 'DECLINED';
    case CANCELLED = 'CANCELLED';
    case PENDING = 'PENDING';

    /**
     * Not approved by the merchant within two days of being created.
     */
    case EXPIRED = 'EXPIRED';

    /**
     * On hold because the shop has not paid Shopify; it becomes active again once payments resume.
     */
    case FROZEN = 'FROZEN';

    /**
     * Create from a stored or API value, ignoring case.
     */
    public static function fromNative(string $name): self
    {
        return self::from(strtoupper($name));
    }
}
