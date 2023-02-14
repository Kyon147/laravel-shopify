<?php

namespace Osiset\ShopifyApp\Exceptions;

/**
 * Exception for handling a missing shop's myshopify domain.
 */
class MissingShopDomainException extends HttpException
{
    protected $code = 401;
}
