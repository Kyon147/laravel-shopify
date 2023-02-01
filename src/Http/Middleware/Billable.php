<?php

namespace Osiset\ShopifyApp\Http\Middleware;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Osiset\ShopifyApp\Contracts\ShopModel as IShopModel;
use Osiset\ShopifyApp\Util;
use RuntimeException;

/**
 * Responsible for ensuring the shop is being billed.
 */
class Billable
{
    /**
     * Checks if a shop has paid for access.
     *
     * @param Request $request The request object.
     * @param Closure $next The next action.
     *
     *@throws Exception
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (Util::useNativeAppBridge() === false) {
            throw new RuntimeException('You cannot use Billable middleware with SPA mode');
        }

        if (Util::getShopifyConfig('billing_enabled') === true) {
            /** @var $shop IShopModel */
            $shop = auth()->user();
            if (!$shop->plan && !$shop->isFreemium() && !$shop->isGrandfathered()) {
                // They're not grandfathered in, and there is no charge or charge was declined... redirect to billing
                return Redirect::route(
                    Util::getShopifyConfig('route_names.billing'),
                    array_merge($request->input(), [
                        'shop' => $shop->getDomain()->toNative(),
                        'host' => $request->get('host'),
                    ])
                );
            }
        }

        // Move on, everything's fine
        return $next($request);
    }
}
