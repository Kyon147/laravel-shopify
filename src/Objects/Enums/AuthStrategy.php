<?php

declare(strict_types=1);

namespace Osiset\ShopifyApp\Objects\Enums;

enum AuthStrategy
{
    case TOKEN_EXCHANGE;
    case AUTH_CODE_FLOW;
}
