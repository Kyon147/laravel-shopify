<?php

namespace Osiset\ShopifyApp\Objects\Enums;

use Funeralzone\ValueObjects\Enums\EnumTrait;
use Funeralzone\ValueObjects\ValueObject;

/**
 * API charge status.
 *
 * @method static ChargeStatus ACTIVE()
 * @method static ChargeStatus ACCEPTED()
 * @method static ChargeStatus DECLINED()
 * @method static ChargeStatus CANCELLED()
 * @method static ChargeStatus PENDING()
 * @method static ChargeStatus EXPIRED()
 * @method static ChargeStatus FROZEN()
 */
final class ChargeStatus implements ValueObject
{
    use EnumTrait;

    /**
     * Status: Active.
     *
     * @var int
     */
    public const ACTIVE = 0;

    /**
     * Status: Accepted.
     *
     * @var int
     */
    public const ACCEPTED = 1;

    /**
     * Status: Declines.
     *
     * @var int
     */
    public const DECLINED = 2;

    /**
     * Status: Cancelled.
     *
     * @var int
     */
    public const CANCELLED = 3;

    /**
     * Status: Pending.
     *
     * @var int
     */
    public const PENDING = 4;

    /**
     * Status: Expired.
     *
     * Not approved by the merchant within two days of being created.
     *
     * @var int
     */
    public const EXPIRED = 5;

    /**
     * Status: Frozen.
     *
     * On hold because the shop has not paid Shopify; it becomes active again once payments resume.
     *
     * @var int
     */
    public const FROZEN = 6;
}
