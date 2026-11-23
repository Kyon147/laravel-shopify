<?php

namespace Osiset\ShopifyApp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;
use Osiset\ShopifyApp\Util;

/**
 * Responsibility for protection against clickjacking
 */
class IframeProtection
{
    /**
     * The shop querier.
     *
     * @var IShopQuery
     */
    protected $shopQuery;

    /**
     * Constructor.
     *
     * @param IShopQuery  $shopQuery The shop querier.
     *
     * @return void
     */
    public function __construct(
        IShopQuery $shopQuery
    ) {
        $this->shopQuery = $shopQuery;
    }

    /**
     * Set frame-ancestors header
     *
     * @param Request  $request The request object.
     * @param \Closure $next    The next action.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $ancestors = Util::getShopifyConfig('iframe_ancestors');

        // Sadece alan adını (string) cache'e atarak serileştirme hatasını engelliyoruz
        $shop = Cache::remember(
            'frame-ancestors_' . $request->get('shop'),
            now()->addMinutes(20),
            function () use ($request) {
                $shopModel = $this->shopQuery->getByDomain(ShopDomain::fromRequest($request));
                return $shopModel ? $shopModel->name : null;
            }
        );

        // Eğer eski cache'ten yarım/bozuk bir nesne gelirse uygulamayı çökertmeden yakalıyoruz
        if (is_object($shop) && get_class($shop) === '__PHP_Incomplete_Class') {
            $shop = null;
        }

        $domain = is_string($shop)
            ? $shop
            : (($shop && isset($shop->name)) ? $shop->name : '*.myshopify.com');

        $iframeAncestors = "frame-ancestors https://$domain https://admin.shopify.com";

        if (!blank($ancestors)) {
            $iframeAncestors .= ' ' . $ancestors;
        }

        $response->headers->set(
            'Content-Security-Policy',
            $iframeAncestors
        );

        return $response;
    }
}