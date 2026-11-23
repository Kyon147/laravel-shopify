<?php

namespace Osiset\ShopifyApp\Http\Middleware;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Redirect;
use Osiset\ShopifyApp\Contracts\ShopModel as IShopModel;
use Osiset\ShopifyApp\Util;

/**
 * Responsible for ensuring the shop is being billed.
 */
class Billable
{
    /**
     * Checks if a shop has paid for access.
     *
     * @param Request $request The request object.
     * @param Closure $next    The next action.
     *
     * @throws Exception
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (Util::getShopifyConfig('billing_enabled') !== true) {
            return $next($request);
        }

        // Proceed if we are on SPA mode & it's a non ajax request
        if (!Util::isMPAApplication() && ! $request->ajax()) {
            return $next($request);
        }

        /** @var $shop IShopModel */
        $shop = auth()->user();

        // if shop has plan or is on freemium or is grandfathered then move on with request
        if (! $shop || $shop->plan || $shop->isFreemium() || $shop->isGrandfathered()) {
            return $next($request);
        }

        // Drop Shopify's signed load params; the HMAC cannot be re-verified once the query is rebuilt
        $input = Arr::except($request->input(), ['hmac', 'timestamp', 'id_token']);
        // Forward the id_token only when it is the token this request was verified with
        $idToken = $request->query('id_token');
        $context = $shop->getSessionContext();
        if (
            !isset($input['token'])
            && is_string($idToken)
            && $context !== null
            && $idToken === $context->getSessionToken()->toNative()
        ) {
            $input['token'] = $idToken;
        }

        $args = [
            Util::getShopifyConfig('route_names.billing'),
            array_merge($input, [
                'shop' => $shop->getDomain()->toNative(),
                'host' => $request->get('host'),
                'locale' => $request->get('locale'),
            ]),
        ];

        if ($request->ajax()) {
            return response()->json(
                ['forceRedirectUrl' => route(...$args)],
                402
            );
        }

        return Redirect::route(...$args);
    }
}
