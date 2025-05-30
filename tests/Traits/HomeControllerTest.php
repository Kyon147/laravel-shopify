<?php

namespace Osiset\ShopifyApp\Test\Traits;

use Illuminate\Auth\AuthManager;
use Osiset\ShopifyApp\Test\TestCase;
use Osiset\ShopifyApp\Util;

class HomeControllerTest extends TestCase
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

    public function testHomeRoute(): void
    {
        $shop = factory($this->model)->create(['name' => 'shop-name.myshopify.com']);

        $host = base64_encode($shop->getDomain()->toNative().'/admin');
        $this->call('get', '/', ['token' => $this->buildToken(), 'host' => $host])
            ->assertOk()
            ->assertSee('name="shopify-api-key" content="'.Util::getShopifyConfig('api_key').'"', false)
            ->assertSee('https://cdn.shopify.com/shopifycloud/app-bridge.js');
    }

    public function testHomeRouteHostAdmin(): void
    {
        factory($this->model)->create(['name' => 'shop-name.myshopify.com']);

        $host = base64_encode('admin.shopify.com/store/shop-name');
        $this->call('get', '/', ['token' => $this->buildToken(), 'host' => $host])
            ->assertOk()
            ->assertSee('name="shopify-api-key" content="'.Util::getShopifyConfig('api_key').'"', false)
            ->assertSee('https://cdn.shopify.com/shopifycloud/app-bridge.js');
    }
}
