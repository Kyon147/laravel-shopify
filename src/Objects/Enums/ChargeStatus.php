<?php

namespace Osiset\ShopifyApp\Objects\Enums;

/**
 * API charge status.
 *
 * Backing values match legacy Funeralzone {@see EnumTrait} {@see toNative()} (constant name strings).
 */
enum ChargeStatus: string
{
    case ACTIVE = 'ACTIVE';
    case ACCEPTED = 'ACCEPTED';
    case DECLINED = 'DECLINED';
    case CANCELLED = 'CANCELLED';
    case PENDING = 'PENDING';

    /**
     * @deprecated Use {@see self::tryFrom()} or enum cases; kept for backward compatibility with ValueObject API.
     */
    public static function fromNative(string $name): self
    {
        return self::from(strtoupper($name));
    }

    public static function fromShopifyApiStatus(string $status): self
    {
        return self::from(strtoupper($status));
    }
}
