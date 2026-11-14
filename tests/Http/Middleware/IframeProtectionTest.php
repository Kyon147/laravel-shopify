<?php

namespace Osiset\ShopifyApp\Test\Http\Middleware;

use Illuminate\Auth\AuthManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Osiset\ShopifyApp\Http\Middleware\IframeProtection;
use Osiset\ShopifyApp\Storage\Queries\Shop as ShopQuery;
use Osiset\ShopifyApp\Test\TestCase;

class IframeProtectionTest extends TestCase
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

    public function testIframeProtectionWithAuthorizedShop(): void
    {
        $shop = factory($this->model)->create();
        $this->auth->login($shop);

        $domain = auth()->user()->name;
        $expectedHeader = "frame-ancestors https://$domain https://admin.shopify.com";

        $request = new Request();
        $shopQueryStub = $this->createStub(ShopQuery::class);
        $shopQueryStub->method('getByDomain')->willReturn($shop);
        $next = function () {
            return new Response('Test Response');
        };

        $middleware = new IframeProtection($shopQueryStub);
        $response = $middleware->handle($request, $next);
        $currentHeader = $response->headers->get('content-security-policy');

        $this->assertNotEmpty($currentHeader);
        $this->assertEquals($expectedHeader, $currentHeader);
    }

    public function testIframeProtectionWithUnauthorizedShop(): void
    {
        $expectedHeader = 'frame-ancestors https://*.myshopify.com https://admin.shopify.com';

        $request = new Request();
        $shopQuery = new ShopQuery();
        $next = function () {
            return new Response('Test Response');
        };

        $middleware = new IframeProtection($shopQuery);
        $response = $middleware->handle($request, $next);
        $currentHeader = $response->headers->get('content-security-policy');

        $this->assertNotEmpty($currentHeader);
        $this->assertEquals($expectedHeader, $currentHeader);
    }

    public function testIframeProtectionWithExistingAncestorsInConfig(): void
    {
        $shop = factory($this->model)->create();
        $this->auth->login($shop);
        $this->app['config']->set('shopify-app.iframe_ancestors', 'https://example.com');

        $domain = auth()->user()->name;
        $expectedHeader = "frame-ancestors https://$domain https://admin.shopify.com https://example.com";

        $request = new Request();
        $shopQueryStub = $this->createStub(ShopQuery::class);
        $shopQueryStub->method('getByDomain')->willReturn($shop);
        $next = function () {
            return new Response('Test Response');
        };

        $middleware = new IframeProtection($shopQueryStub);
        $response = $middleware->handle($request, $next);
        $currentHeader = $response->headers->get('content-security-policy');

        $this->assertNotEmpty($currentHeader);
        $this->assertEquals($expectedHeader, $currentHeader);
    }

    public function testIframeProtectionWithCachedString(): void
    {
        $expectedHeader = 'frame-ancestors https://cached-shop.myshopify.com https://admin.shopify.com';

        \Illuminate\Support\Facades\Cache::put('frame-ancestors_test-shop', 'cached-shop.myshopify.com', 20);

        $request = new Request(['shop' => 'test-shop']);
        $shopQuery = $this->createMock(ShopQuery::class);
        $shopQuery->expects($this->never())->method('getByDomain');
        $next = function () {
            return new Response('Test Response');
        };

        $middleware = new IframeProtection($shopQuery);
        $response = $middleware->handle($request, $next);
        $currentHeader = $response->headers->get('content-security-policy');

        $this->assertEquals($expectedHeader, $currentHeader);
    }

    public function testIframeProtectionWithCachedIncompleteObject(): void
    {
        $expectedHeader = 'frame-ancestors https://*.myshopify.com https://admin.shopify.com';

        $incompleteObject = unserialize('O:21:"NonExistingDummyClass":0:{}');
        \Illuminate\Support\Facades\Cache::put('frame-ancestors_test-shop', $incompleteObject, 20);

        $request = new Request(['shop' => 'test-shop']);
        $shopQuery = $this->createMock(ShopQuery::class);
        $shopQuery->expects($this->never())->method('getByDomain');
        $next = function () {
            return new Response('Test Response');
        };

        $middleware = new IframeProtection($shopQuery);
        $response = $middleware->handle($request, $next);
        $currentHeader = $response->headers->get('content-security-policy');

        $this->assertEquals($expectedHeader, $currentHeader);
    }
}