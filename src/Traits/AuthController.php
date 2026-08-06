<?php

namespace Osiset\ShopifyApp\Traits;

use Illuminate\Contracts\View\View as ViewView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\View;
use Osiset\ShopifyApp\Actions\AuthenticateShop;
use Osiset\ShopifyApp\Exceptions\MissingAuthUrlException;
use Osiset\ShopifyApp\Exceptions\MissingShopDomainException;
use Osiset\ShopifyApp\Exceptions\SignatureVerificationException;
use Osiset\ShopifyApp\Messaging\Events\ShopAuthenticatedEvent;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;
use Osiset\ShopifyApp\Util;

/**
 * Responsible for authenticating the shop.
 */
trait AuthController
{
    /**
     * Installing/authenticating a shop.
     *
     * @throws MissingShopDomainException if both shop parameter and authenticated user are missing
     *
     * @return ViewView|RedirectResponse
     */
    public function authenticate(Request $request, AuthenticateShop $authShop)
    {
        if ($request->missing('shop') && !$request->user()) {
            // One or the other is required to authenticate a shop
            throw new MissingShopDomainException('No authenticated user or shop domain');
        }

        // Get the shop domain
        $shopDomain = $request->has('shop')
            ? ShopDomain::fromNative($request->get('shop'))
            : $request->user()->getDomain();

        // If the domain is obtained from $request->user()
        if ($request->missing('shop')) {
            $request['shop'] = $shopDomain->toNative();
        }

        // Run the action
        [$result, $status] = $authShop($request);

        if ($status === null) {
            // Show exception, something is wrong
            throw new SignatureVerificationException('Invalid HMAC verification');
        } elseif ($status === false) {
            if (!$result['url']) {
                throw new MissingAuthUrlException('Missing auth url');
            }

            $shopDomain = $shopDomain->toNative();
            $shopOrigin = $shopDomain ?? $request->user()->name;

            event(new ShopAuthenticatedEvent($result['shop_id']));

            return View::make(
                'shopify-app::auth.fullpage_redirect',
                [
                    'apiKey' => Util::getShopifyConfig('api_key', $shopOrigin),
                    'url' => $result['url'],
                    'host' => $request->get('host'),
                    'shopDomain' => $shopDomain,
                    'locale' => $request->get('locale'),
                ]
            );
        } else {
            // Go to home route
            return Redirect::route(
                Util::getShopifyConfig('route_names.home'),
                [
                    'shop' => $shopDomain->toNative(),
                    'host' => $request->get('host'),
                    'locale' => $request->get('locale'),
                ]
            );
        }
    }

    /**
     * Get session token for a shop.
     *
     * @return ViewView
     */
    public function token(Request $request)
    {
        $request->session()->reflash();
        $shopDomain = ShopDomain::fromRequest($request);

        $target = Util::sanitizeTokenRedirectTarget(
            $request->query('target'),
            $request->getSchemeAndHttpHost()
        );

        $fallbackPath = parse_url(route(Util::getShopifyConfig('route_names.home'), [], false), PHP_URL_PATH) ?: '/';

        return View::make(
            'shopify-app::auth.token',
            [
                'shopDomain' => $shopDomain->toNative(),
                'target' => $this->buildCleanTokenTarget($request, $shopDomain, $target),
                'fallbackTarget' => $this->buildCleanTokenTarget($request, $shopDomain, $fallbackPath),
            ]
        );
    }

    /**
     * Build a token redirect target with shop, host, and locale query params.
     */
    protected function buildCleanTokenTarget(Request $request, ShopDomain $shopDomain, string $target): string
    {
        $query = parse_url($target, PHP_URL_QUERY);

        if ($query) {
            $params = Util::parseQueryString($query);
            $params['shop'] = $params['shop'] ?? $shopDomain->toNative() ?? '';
            $params['host'] = $request->get('host');
            $params['locale'] = $request->get('locale');
            unset($params['token']);

            return trim(explode('?', $target)[0].'?'.http_build_query($params), '?');
        }

        $params = [
            'shop' => $shopDomain->toNative() ?? '',
            'host' => $request->get('host'),
            'locale' => $request->get('locale'),
        ];

        return trim(explode('?', $target)[0].'?'.http_build_query($params), '?');
    }
}
