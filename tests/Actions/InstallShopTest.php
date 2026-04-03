<?php

namespace Osiset\ShopifyApp\Test\Actions;

use Illuminate\Support\Facades\Crypt;
use Osiset\ShopifyApp\Actions\InstallShop;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;
use Osiset\ShopifyApp\Test\Stubs\Api as ApiStub;
use Osiset\ShopifyApp\Test\TestCase;
use Osiset\ShopifyApp\Util;

class InstallShopTest extends TestCase
{
    /**
     * @var \Osiset\ShopifyApp\Actions\InstallShop
     */
    protected $action;

    public function setUp(): void
    {
        parent::setUp();

        $this->action = $this->app->make(InstallShop::class);
    }

    public function testNoShopShouldBeMade(): void
    {
        $result = call_user_func(
            $this->action,
            ShopDomain::fromNative('non-existant.myshopify.com'),
            null
        );

        $this->assertStringContainsString(
            '/admin/oauth/authorize?client_id='.Util::getShopifyConfig('api_key').'&scope=read_products%2Cwrite_products%2Cread_themes&redirect_uri=https%3A%2F%2Flocalhost%2Fauthenticate',
            $result['url']
        );
        $this->assertFalse($result['completed']);
        $this->assertNotNull($result['shop_id']);
    }

    public function testWithoutCode(): void
    {
        // Create the shop
        $shop = factory($this->model)->create();

        $result = call_user_func(
            $this->action,
            $shop->getDomain(),
            null
        );

        $this->assertStringContainsString(
            '/admin/oauth/authorize?client_id='.Util::getShopifyConfig('api_key').'&scope=read_products%2Cwrite_products%2Cread_themes&redirect_uri=https%3A%2F%2Flocalhost%2Fauthenticate',
            $result['url']
        );
        $this->assertFalse($result['completed']);
        $this->assertNotNull($result['shop_id']);
    }

    public function testWithCode(): void
    {
        // Create the shop
        $shop = factory($this->model)->create();

        // Get the current access token
        $currentToken = $shop->getAccessToken();

        // Setup API stub
        $this->setApiStub();
        ApiStub::stubResponses(['access_token']);

        $result = call_user_func(
            $this->action,
            $shop->getDomain(),
            '12345678'
        );

        // Refresh to see changes
        $shop->refresh();

        $this->assertTrue($result['completed']);
        $this->assertNotNull($result['shop_id']);
        $this->assertNotSame($currentToken->toNative(), $shop->getAccessToken()->toNative());
    }

    public function testWithCodeSoftDeletedShop(): void
    {
        // Create the shop
        $shop = factory($this->model)->create([
            'deleted_at' => $this->now->getTimestamp(),
        ]);

        // Get the current access token
        $currentToken = $shop->getAccessToken();

        // Setup API stub
        $this->setApiStub();
        ApiStub::stubResponses(['access_token']);

        $result = call_user_func(
            $this->action,
            $shop->getDomain(),
            '12345678'
        );

        // Refresh to see changes
        $shop->refresh();

        $this->assertTrue($result['completed']);
        $this->assertNotNull($result['shop_id']);
        $this->assertNotSame($currentToken->toNative(), $shop->getAccessToken()->toNative());
    }

    public function testManagedAppInstall(): void
    {
        // Setup API stub
        $this->setApiStub();
        ApiStub::stubResponses(['access_token']);

        $this->assertDatabaseMissing(
            $this->model,
            [
            'name' => 'test.myshopify.com',
            ]
        );

        $result = call_user_func(
            $this->action,
            ShopDomain::fromNative('test.myshopify.com'),
            null,
            '1234'
        );

        $this->assertDatabaseHas($this->model, [
            'id' => $result['shop_id']->toNative(),
            'name' => 'test.myshopify.com',
            /*
             * Password as per the test fixture.
             * @see ../../tests/fixtures/access_token.json
             */
            'password' => '12345678',
        ]);
    }

    public function testWithCodeStoresExpiringOfflineMetadataWhenEnabled(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        $shop = factory($this->model)->create();

        $this->setApiStub();
        ApiStub::stubResponses(['access_token_expiring', 'get_themes']);

        $result = call_user_func(
            $this->action,
            $shop->getDomain(),
            '12345678'
        );

        $this->assertTrue($result['completed']);
        $shop->refresh();

        $this->assertSame('shpat_expiring_test_token', $shop->getAccessToken()->toNative());
        $this->assertNotNull($shop->shopify_offline_refresh_token);
        $this->assertSame(
            'shprt_expiring_test_refresh',
            Crypt::decryptString($shop->shopify_offline_refresh_token)
        );
        $this->assertNotNull($shop->shopify_offline_access_token_expires_at);
        $this->assertNotNull($shop->shopify_offline_refresh_token_expires_at);
    }

    public function testWithCodeHandlesOAuthFailureWhenExpiringOfflineEnabled(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        $shop = factory($this->model)->create();

        $this->setApiStub();
        ApiStub::stubResponses(['oauth_access_token_error']);

        $result = call_user_func(
            $this->action,
            $shop->getDomain(),
            'badcode'
        );

        $this->assertFalse($result['completed']);
        $this->assertNull($result['url']);
        $this->assertNull($result['shop_id']);
        $this->assertNull($result['theme_support_level']);
    }

    public function testManagedAppInstallWithExpiringOfflineUsesTokenExchange(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        $this->setApiStub();
        ApiStub::stubResponses(['access_token', 'get_themes']);

        $this->assertDatabaseMissing($this->model, [
            'name' => 'test.myshopify.com',
        ]);

        $result = call_user_func(
            $this->action,
            ShopDomain::fromNative('test.myshopify.com'),
            null,
            $this->buildToken(['dest' => 'https://test.myshopify.com'])
        );

        $this->assertTrue($result['completed']);
        $this->assertDatabaseHas($this->model, [
            'id' => $result['shop_id']->toNative(),
            'name' => 'test.myshopify.com',
            'password' => '12345678',
        ]);
    }
}
