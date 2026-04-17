<?php

namespace Osiset\ShopifyApp\Exceptions;

use Exception;

/**
 * Thrown when refreshing an expiring offline access token fails (e.g. invalid or expired refresh token).
 */
class OAuthTokenRefreshException extends Exception
{
}