<?php

namespace Osiset\ShopifyApp\Directives;

/**
 * Provides a Blade directive for session tokens.
 */
class SessionToken
{
    /**
     * Output for the directive.
     *
     * @return string
     */
    public function __invoke(): string
    {
        return '<input type="hidden" class="session-token" name="token" value="" /><input type="hidden" name="host" value="'.str_replace('"', htmlentities('"'), e(request('host'))).'"><input type="hidden" name="shop" value="'.str_replace('"', htmlentities('"'), e(request('shop'))).'">';
    }
}
