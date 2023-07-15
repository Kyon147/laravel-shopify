<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
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
        $user        = User::where('name', $request->get('shop'))->first();
        $scopes_resp = $user->api()->rest('GET', '/admin/oauth/access_scopes.json');

        if ($scopes_resp["errors"]) {
          //  \Log::debug("Error in fetching scope");
            return $next($request);
        }


        $scopes = json_decode(json_encode($scopes_resp["body"]["access_scopes"]));
        $scopes = array_map(function ($scope)
        {
            return $scope->handle;
        }, $scopes);

        $requiredScopes = explode(',', env('SHOPIFY_API_SCOPES'));

        $missingScopes = array_diff($requiredScopes, $scopes);
        if (count($missingScopes) == 0) {            
            //Log::debug("all required scopes available");
            return $next($request);
        }

        //Log::debug("Scope missing. Reauthenticate the App");

        return redirect()->route(
            Util::getShopifyConfig('route_names.authenticate'),
            [
                'shop' => $user->name,
                'host' => $request->get('host')
            ]
        );
    }
}
