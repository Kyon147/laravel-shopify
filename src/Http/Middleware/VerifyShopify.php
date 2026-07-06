<?php

namespace Osiset\ShopifyApp\Http\Middleware;

use Assert\AssertionFailedException;
use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Osiset\ShopifyApp\Actions\VerifyHmac;
use Osiset\ShopifyApp\Contracts\Objects\Values\ShopDomain as ShopDomainValue;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Contracts\ShopModel;
use Osiset\ShopifyApp\Exceptions\HttpException;
use Osiset\ShopifyApp\Exceptions\SignatureVerificationException;
use Osiset\ShopifyApp\Objects\Enums\AuthStrategy;
use Osiset\ShopifyApp\Objects\Values\NullableSessionId;
use Osiset\ShopifyApp\Objects\Values\SessionContext;
use Osiset\ShopifyApp\Objects\Values\SessionToken;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;
use Osiset\ShopifyApp\Util;

/**
 * Responsible for validating the request.
 */
class VerifyShopify
{
    /**
     * Constructor.
     *
     * @param AuthManager $auth       The Laravel auth manager.
     * @param IShopQuery  $shopQuery  The shop querier.
     * @param VerifyHmac  $verifyHmac The HMAC verification action.
     *
     * @return void
     */
    public function __construct(
        protected AuthManager $auth,
        protected IShopQuery $shopQuery,
        protected VerifyHmac $verifyHmac
    ) {
    }

    /**
     * Verify the request.
     *
     * @param Request $request The request object.
     * @param Closure $next    The next action.
     *
     * @throws SignatureVerificationException|HttpException If HMAC verification fails.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Verify the HMAC (if available)
        $hmacResult = $this->verifyHmac->handle($request);

        if ($hmacResult === false) {
            // Invalid HMAC
            throw new SignatureVerificationException('Unable to verify signature.');
        }

        // Continue if current route is an auth or billing route
        if (Str::contains($request->getRequestUri(), ['/authenticate', '/billing'])) {
            return $next($request);
        }

        if (!Util::isMPAApplication()) {
            $shop = $this->getShopIfAlreadyInstalled($request);
            $storeResult = !$this->isApiRequest($request) && $shop;

            if ($storeResult) {
                $this->loginFromShop($shop);

                return $next($request);
            }
        }

        $tokenSource = $this->getAccessTokenFromRequest($request);

        if ($tokenSource === null) {
            $forbiddenMiddlewareMatches = array_intersect(
                Util::getShopifyConfig('forbidden_web_middleware_groups'),
                $request->route()?->middleware() ?? []
            );

            if (filled($forbiddenMiddlewareMatches)) {
                throw new HttpException('Access denied.', Response::HTTP_FORBIDDEN);
            }

            //Check if there is a store record in the database
            return (bool) $this->getShopIfAlreadyInstalled($request)
                // Shop exists, token not available, we need to get one
                ? $this->handleMissingToken($request)
                // Shop does not exist
                : $this->handleInvalidShop($request);
        }

        try {
            // Try and process the token
            $token = SessionToken::fromNative($tokenSource);

            if (Util::getShopifyConfig('auth_strategy') === AuthStrategy::TOKEN_EXCHANGE) {
                $domain = $token->getShopDomain();
                $shop = $this->shopQuery->getByDomain($domain, [], true);

                if (! $shop || $shop->trashed()) {
                    if (! $this->isApiRequest($request) && $request->missing('id_token')) {
                        $apiKey = Util::getShopifyConfig('api_key');

                        return Redirect::to("https://{$domain->toNative()}/admin/oauth/install?client_id={$apiKey}");
                    }

                    return $this->handleInvalidShop($request);
                }
            }
        } catch (AssertionFailedException $e) {
            // Invalid or expired token, we need a new one
            return $this->handleInvalidToken($request, $e);
        }

        // Login the shop
        $loginResult = $this->loginShopFromToken(
            $token,
            NullableSessionId::fromNative($request->query('session'))
        );
        if (! $loginResult) {
            // Shop is not installed or something is missing from it's data
            return $this->handleInvalidShop($request);
        }

        return $next($request);
    }

    /**
     * Handle missing token.
     *
     * @param Request $request The request object.
     *
     * @throws HttpException If an AJAX/JSON request.
     *
     * @return mixed
     */
    protected function handleMissingToken(Request $request)
    {
        if ($this->isApiRequest($request)) {
            // AJAX, return HTTP exception
            throw new HttpException(SessionToken::EXCEPTION_INVALID, Response::HTTP_BAD_REQUEST);
        }

        return $this->tokenRedirect($request);
    }

    /**
     * Handle an invalid or expired token.
     *
     * @param Request                  $request The request object.
     * @param AssertionFailedException $e       The assertion failure exception.
     *
     * @throws HttpException If an AJAX/JSON request.
     *
     * @return mixed
     */
    protected function handleInvalidToken(Request $request, AssertionFailedException $e)
    {
        $isExpired = $e->getMessage() === SessionToken::EXCEPTION_EXPIRED;
        if ($this->isApiRequest($request)) {
            // AJAX, return HTTP exception
            throw new HttpException(
                $e->getMessage(),
                $isExpired ? Response::HTTP_FORBIDDEN : Response::HTTP_BAD_REQUEST
            );
        }

        return $this->tokenRedirect($request);
    }

    /**
     * Handle a shop that is not installed or it's data is invalid.
     *
     * @param Request $request The request object.
     *
     * @throws HttpException If an AJAX/JSON request.
     *
     * @return mixed
     */
    protected function handleInvalidShop(Request $request)
    {
        if ($this->isApiRequest($request)) {
            // AJAX, return HTTP exception
            throw new HttpException('Shop is not installed or missing data.', Response::HTTP_FORBIDDEN);
        }

        return $this->installRedirect(
            ShopDomain::fromRequest($request),
            $request->has('id_token')
                ? $request->query('id_token')
                : null
        );
    }

    /**
     * Login and verify the shop and it's data.
     *
     * @param SessionToken      $token     The session token.
     * @param NullableSessionId $sessionId Incoming session ID (if available).
     *
     * @return bool
     */
    protected function loginShopFromToken(SessionToken $token, NullableSessionId $sessionId): bool
    {
        // Get the shop
        $shop = $this->shopQuery->getByDomain($token->getShopDomain(), [], true);
        if (! $shop) {
            return false;
        }

        // Set the session details for the token, session ID, and access token
        $context = new SessionContext($token, $sessionId, $shop->getAccessToken());
        $shop->setSessionContext($context);

        // Override auth guard
        if (($guard = Util::getShopifyConfig('shop_auth_guard'))) {
            $this->auth->setDefaultDriver($guard);
        }

        // All is well, login the shop
        $this->auth->login($shop);

        return true;
    }

    /**
     * Redirect to token route.
     *
     * @param Request $request The request object.
     *
     * @return RedirectResponse
     */
    protected function tokenRedirect(Request $request): RedirectResponse
    {
        // At this point the HMAC and other details are verified already, filter it out
        $path = $request->path();
        $target = Str::start($path, '/');

        if ($request->query()) {
            $filteredQuery = Collection::make($request->query())->except([
                'hmac',
                'locale',
                'new_design_language',
                'timestamp',
                'session',
                'shop',
            ]);

            if ($filteredQuery->isNotEmpty()) {
                $target .= '?'.http_build_query($filteredQuery->toArray());
            }
        }

        return Redirect::route(
            Util::getShopifyConfig('route_names.authenticate.token'),
            [
                'shop' => ShopDomain::fromRequest($request)->toNative(),
                'target' => $target,
                'host' => $request->get('host'),
                'locale' => $request->get('locale'),
            ]
        );
    }

    /**
     * Redirect to install route.
     *
     * @param ShopDomainValue $shopDomain The shop domain.
     * @param string|null     $token      The id token (if available).
     *
     * @return RedirectResponse
     */
    protected function installRedirect(ShopDomainValue $shopDomain, ?string $token = null): RedirectResponse
    {
        $defaultRouteParams = [
            'shop' => $shopDomain->toNative(),
            'host' => request('host'),
            'locale' => request('locale'),
        ];

        return Redirect::route(
            Util::getShopifyConfig('route_names.authenticate'),
            array_merge($defaultRouteParams, $token !== null
                ? ['id_token' => $token]
                : [])
        );
    }

    /**
     * Get the token from request (if available).
     *
     * @param Request $request The request object.
     *
     * @return string
     */
    protected function getAccessTokenFromRequest(Request $request): ?string
    {
        return $this->isApiRequest($request)
            ? $request->bearerToken()
            : $request->get('token') ?? $request->get('id_token');
    }

    /**
     * Determine if the request is AJAX or expects JSON.
     *
     * @param Request $request The request object.
     *
     * @return bool
     */
    protected function isApiRequest(Request $request): bool
    {
        return $request->ajax() || $request->expectsJson();
    }

    /**
     * Get shop model if there is a store record in the database.
     *
     * @param Request $request The request object.
     *
     * @return ?ShopModel
     */
    protected function getShopIfAlreadyInstalled(Request $request): ?ShopModel
    {
        $shop = $this->shopQuery->getByDomain(ShopDomain::fromRequest($request), [], true);

        return $shop && $shop->password && ! $shop->trashed() && ! $shop->hasCorruptExpiringTokenState() ? $shop : null;
    }

    /**
     * Login and validate store
     *
     * @param ShopModel $shop
     *
     * @return void
     */
    protected function loginFromShop(ShopModel $shop): void
    {
        // Override auth guard
        if (($guard = Util::getShopifyConfig('shop_auth_guard'))) {
            $this->auth->setDefaultDriver($guard);
        }

        // All is well, login the shop
        $this->auth->login($shop);
    }
}
