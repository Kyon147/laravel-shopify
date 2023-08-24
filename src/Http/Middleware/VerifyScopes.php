<?php

namespace Osiset\ShopifyApp\Http\Middleware;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Osiset\ShopifyApp\Contracts\ShopModel as IShopModel;
use Osiset\ShopifyApp\Util;

class VerifyScopes
{
    /**
     * Checks if a shop has all required access scopes.
     * If a required access scope is missing, it will redirect the app
     * for re-authentication
     *
     * @param Request $request The request object.
     * @param Closure $next The next action.
     *
     * @throws Exception
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        /** @var $shop IShopModel */
        $shop = auth()->user();
        $scopesResponse = $shop->api()->rest('GET', '/admin/oauth/access_scopes.json');
        if ($scopesResponse && $scopesResponse['errors']) {
            return $next($request);
        }
        $scopes = json_decode(json_encode($scopesResponse['body']['access_scopes']), false);
        $scopes = array_map(static function ($scope) {
            return $scope->handle;
        }, $scopes);

        $requiredScopes = explode(',', Util::getShopifyConfig('api_scopes'));
        $missingScopes = array_diff($requiredScopes, $scopes);
        if (count($missingScopes) === 0) {
            return $next($request);
        }

        return redirect()->route(
            Util::getShopifyConfig('route_names.authenticate'),
            [
                'shop' => $shop->getDomain()->toNative(),
                'host' => $request->get('host'),
            ]
        );
    }
}
