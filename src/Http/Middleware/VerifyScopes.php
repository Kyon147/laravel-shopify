<?php

namespace Osiset\ShopifyApp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, Log, Redirect};
use Osiset\ShopifyApp\Contracts\ShopModel;
use Osiset\ShopifyApp\Util;

class VerifyScopes
{
    private const CURRENT_SCOPES_CACHE_KEY = 'currentScopes';

    /**
     * Handle an incoming request.
     *
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        /** @var ?ShopModel */
        $shop = auth()->user();

        if ($shop) {
            $response = $this->currentScopes($shop);

            if ($response['hasErrors']) {
                return $next($request);
            }

            $hasMissingScopes = filled(
                array_diff(
                    explode(',', config('shopify-app.api_scopes')),
                    $response['result']
                )
            );

            if ($hasMissingScopes) {
                Cache::forget($this->cacheKey($shop->getDomain()->toNative()));

                return Redirect::route(Util::getShopifyConfig('route_names.authenticate'), [
                    'shop' => $shop->getDomain()->toNative(),
                    'host' => $request->get('host'),
                    'locale' => $request->get('locale'),
                ]);
            }
        }

        return $next($request);
    }

    /**
     * Fetches the current Shopify app access scopes.
     *
     * Successful Shopify API responses are cached to avoid unnecessary
     * requests on every middleware execution. Failed API responses are
     * intentionally not cached because Shopify errors may be temporary.
     * Caching an error response would cause subsequent requests to keep
     * failing until the cache expires instead of retrying the Shopify API.
     *
     * @return array{hasErrors: bool, result: string[]}
     */
    private function currentScopes(ShopModel $shop): array
    {
        $cacheKey = $this->cacheKey($shop->getDomain()->toNative());

        /** @var array{errors: bool, status: int, body: \Gnikyt\BasicShopifyAPI\ResponseAccess}|null $response */
        $response = Cache::get($cacheKey);

        if ($response === null) {
            $response = $shop->api()->graph('{
                currentAppInstallation {
                    accessScopes {
                        handle
                    }
                }
            }');

            $hasErrors = $response['errors']
                || filled(
                    data_get(
                        $response['body']->toArray(),
                        'data.currentAppInstallation.userErrors'
                    )
                );

            if (! $hasErrors) {
                Cache::put(
                    $cacheKey,
                    $response,
                    now()->addDay()
                );
            }
        }

        if (
            ! $response['errors']
            && blank(
                data_get(
                    $response['body']->toArray(),
                    'data.currentAppInstallation.userErrors'
                )
            )
        ) {
            return [
                'hasErrors' => false,
                'result' => array_column(
                    data_get(
                        $response['body'],
                        'data.currentAppInstallation.accessScopes'
                    )->toArray(),
                    'handle'
                ),
            ];
        }

        // Also removes an error response cached by an older package version.
        Cache::forget($cacheKey);

        Log::error(
            'Fetch current app installation access scopes error: '
            .json_encode(
                data_get(
                    $response['body']->toArray(),
                    'data.currentAppInstallation.userErrors'
                )
            )
        );

        return [
            'hasErrors' => true,
            'result' => [],
        ];
    }

    private function cacheKey(string $shopDomain): string
    {
        return sprintf("{$shopDomain}.%s", self::CURRENT_SCOPES_CACHE_KEY);
    }
}
