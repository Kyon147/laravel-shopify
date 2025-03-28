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
        $result = $middleware->handle($request, function () {});

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
        $result = $middleware->handle($request, function () {});

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
        $result = $middleware->handle($request, function () {});

        $this->assertEquals($result, null);
    }
}
