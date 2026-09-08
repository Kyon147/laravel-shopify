<?php

namespace Osiset\ShopifyApp\Test\Http\Middleware;

use Illuminate\Auth\AuthManager;
use Illuminate\Http\Request;
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

    public function testMissingScopesFromConfigApiCallback(): void
    {
        $this->setApiStub();
        ApiStub::stubResponses(['access_scopes']);

        $shop = factory($this->model)->create(['name' => 'shop-a.myshopify.com']);
        factory($this->model)->create(['name' => 'shop-b.myshopify.com']);

        $this->app['config']->set('shopify-app.api_scopes', 'read_products,write_products');
        $this->app['config']->set('shopify-app.config_api_callback', function (string $key, $configShop) {
            if ($key !== 'api_scopes') {
                return config("shopify-app.{$key}");
            }

            $domain = is_object($configShop) && method_exists($configShop, 'getDomain')
                ? $configShop->getDomain()->toNative()
                : (string) $configShop;

            return $domain === 'shop-a.myshopify.com'
                ? 'read_products,write_products,read_orders'
                : 'read_products,write_products';
        });

        $this->auth->login($shop);

        $request = Request::create('/', 'GET', ['shop' => $shop->getDomain()->toNative()]);

        $middleware = new VerifyScopesMiddleware();
        $result = $middleware->handle($request, function () {
        });

        $this->assertNotNull($result);
        $this->assertEquals(302, $result->getStatusCode());
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
}
