<?php

namespace Osiset\ShopifyApp\Objects\Enums;

use Funeralzone\ValueObjects\Enums\EnumTrait;
use Funeralzone\ValueObjects\ValueObject;

class FrontendType implements ValueObject
{
    use EnumTrait;

    /**
     * @var int
     */
    public const MPA = 0;

    /**
     * @var int
     */
    public const SPA = 1;
}
