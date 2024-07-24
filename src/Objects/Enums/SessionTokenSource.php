<?php

namespace Osiset\ShopifyApp\Objects\Enums;

use Funeralzone\ValueObjects\Enums\EnumTrait;
use Funeralzone\ValueObjects\ValueObject;

/**
 * API call method types.
 *
 * @method static SessionTokenSource APP()
 * @method static SessionTokenSource CHECKOUT_EXTENSION()
 */
final class SessionTokenSource implements ValueObject
{
    use EnumTrait;

    /**
     * Token form Shopify App
     *
     * @var int
     */
    public const APP = 0;

    /**
     * Token from UI extension
     *
     * @var int
     */
    public const CHECKOUT_EXTENSION = 1;
}
