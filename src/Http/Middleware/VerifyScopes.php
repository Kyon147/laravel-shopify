<?php

use Closure;
namespace App\Http\Middleware;
use Illuminate\Http\Request;
use Osiset\ShopifyApp\Util;

class VerifyScopes
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $shop = auth()->user();
        $scopesResponse = $shop->api()->rest('GET', '/admin/oauth/access_scopes.json');
        if ($scopesResponse["errors"]) {          
            return $next($request);
        }
        
        $scopes = json_decode(json_encode($scopesResponse["body"]["access_scopes"]));
        $scopes = array_map(function ($scope)
        {
            return $scope->handle;
        }, $scopes);

        $requiredScopes = explode(',', env('SHOPIFY_API_SCOPES'));
        $missingScopes = array_diff($requiredScopes, $scopes);
        if (count($missingScopes) == 0) {                        
            return $next($request);
        }        

        return redirect()->route(
            Util::getShopifyConfig('route_names.authenticate'),
            [
                'shop' => $shop->getDomain()->toNative(),
                'host' => $request->get('host')
            ]
        );
    }
}
