<?php

namespace Osiset\ShopifyApp\Test\Http\Middleware;

use Illuminate\Auth\AuthManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Osiset\ShopifyApp\Http\Middleware\VerifyScopes as VerifyScopesMiddleware;
use Osiset\ShopifyApp\Test\Stubs\Api as ApiStub;
use Osiset\ShopifyApp\Test\TestCase;

class VerifyScopesTest extends TestCase
{
    /**
     * @var AuthManager
     */
    protected $auth;

    public function setUp(): void
    {
        parent::setUp();

        $this->auth = $this->app->make(AuthManager::class);
    }

    public function testMissingScopes(): void
    {
        $this->setApiStub();
        ApiStub::stubResponses(['access_scopes']);

        $this->app['config']->set('shopify-app.api_scopes', 'read_products,write_products,read_orders');

        $shop = factory($this->model)->create();
        $this->auth->login($shop);

        $request = Request::create('/', 'GET', ['shop' => $shop->getDomain()->toNative()]);

        $middleware = new VerifyScopesMiddleware();
        $result = $middleware->handle($request, function () {
        });

        $this->assertEquals(302, $result->getStatusCode());
    }

    public function testMatchingScopes(): void
    {
        $this->setApiStub();
        ApiStub::stubResponses(['access_scopes']);

        $this->app['config']->set('shopify-app.api_scopes', 'read_products,write_products');

        $shop = factory($this->model)->create();
        $this->auth->login($shop);

        $request = Request::create('/', 'GET', ['shop' => $shop->getDomain()->toNative()]);

        $middleware = new VerifyScopesMiddleware();
        $result = $middleware->handle($request, function () {
        });

        $this->assertEquals($result, null);
    }

    public function testScopeApiFailure(): void
    {
        $this->setApiStub();
        ApiStub::stubResponses(['access_scopes_error']);

        $this->app['config']->set('shopify-app.api_scopes', 'read_products,write_products');

        $shop = factory($this->model)->create();
        $this->auth->login($shop);

        $request = Request::create('/', 'GET', ['shop' => $shop->getDomain()->toNative()]);

        $middleware = new VerifyScopesMiddleware();
        $result = $middleware->handle($request, function () {
        });

        $this->assertEquals($result, null);
    }

    public function testScopeApiFailureIsNotCached(): void
    {
        $this->setApiStub();

        // First request fails, second request succeeds.
        ApiStub::stubResponses([
            'access_scopes_error',
            'access_scopes',
        ]);

        $this->app['config']->set(
            'shopify-app.api_scopes',
            'read_products,write_products'
        );

        $shop = factory($this->model)->create();
        $this->auth->login($shop);

        $request = Request::create(
            '/',
            'GET',
            ['shop' => $shop->getDomain()->toNative()]
        );

        $middleware = new VerifyScopesMiddleware();

        // First request receives Shopify API error.
        $middleware->handle($request, function () {
        });

        $cacheKey = sprintf(
            '%s.currentScopes',
            $shop->getDomain()->toNative()
        );

        // Failed Shopify responses must never be cached.
        $this->assertFalse(Cache::has($cacheKey));

        // Second request should call Shopify again and succeed.
        $middleware->handle($request, function () {
        });

        // Successful response should now be cached.
        $this->assertTrue(Cache::has($cacheKey));

        // Both stub responses should have been consumed.
        $this->assertEmpty(ApiStub::$stubFiles);
    }
}
