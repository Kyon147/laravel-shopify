<?php

namespace Osiset\ShopifyApp\Test\Traits;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Osiset\ShopifyApp\Test\Stubs\Api as ApiStub;
use Osiset\ShopifyApp\Test\TestCase;

class ShopModelApiClientRefreshTest extends TestCase
{
    protected function configureExpiringTokens(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);
        $this->app['config']->set('shopify-app.auto_migrate_legacy', false);
    }

    protected function createShopWithExpiringToken(string $password = 'shpat_old'): object
    {
        return factory($this->model)->create([
            'password' => $password,
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_old'),
            'shopify_offline_access_token_expires_at' => Carbon::now()->addHour(),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(60),
        ]);
    }

    public function testOfflineAccessTokenIsFreshReflectsExpiryState(): void
    {
        $this->configureExpiringTokens();

        $freshShop = $this->createShopWithExpiringToken();
        $this->assertTrue($freshShop->offlineAccessTokenIsFresh());

        $expiredShop = factory($this->model)->create([
            'password' => 'shpat_old',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_old'),
            'shopify_offline_access_token_expires_at' => Carbon::now()->subMinutes(5),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(60),
        ]);

        $this->assertFalse($expiredShop->offlineAccessTokenIsFresh());
    }

    public function testCachedClientDoesNotRefreshWhenConfigDisabled(): void
    {
        $this->configureExpiringTokens();
        $this->app['config']->set('shopify-app.refresh_offline_token_before_api_call', false);

        $shop = $this->createShopWithExpiringToken();
        $shop->api();

        Carbon::setTestNow(Carbon::now()->addHours(2));

        $this->setApiStub();
        ApiStub::stubResponses(['oauth_offline_refresh']);

        $shop->api();

        $shop->refresh();

        $this->assertSame('shpat_old', $shop->getAccessToken()->toNative());
        $this->assertSame(['oauth_offline_refresh'], ApiStub::$stubFiles);
    }

    public function testCachedClientRefreshesWhenConfigEnabled(): void
    {
        $this->configureExpiringTokens();
        $this->app['config']->set('shopify-app.refresh_offline_token_before_api_call', true);

        $shop = $this->createShopWithExpiringToken();
        $shop->api();

        Carbon::setTestNow(Carbon::now()->addHours(2));

        $this->setApiStub();
        ApiStub::stubResponses(['oauth_offline_refresh']);

        $shop->api();

        $shop->refresh();

        $this->assertSame('shpat_after_refresh', $shop->getAccessToken()->toNative());
        $this->assertSame([], ApiStub::$stubFiles);
    }

    public function testRefreshOfflineAccessTokenIfNeededUpdatesTokenAndClearsCache(): void
    {
        $this->configureExpiringTokens();

        $shop = $this->createShopWithExpiringToken();
        $shop->api();
        $this->assertNotNull($shop->apiHelper);

        Carbon::setTestNow(Carbon::now()->addHours(2));

        $this->setApiStub();
        ApiStub::stubResponses(['oauth_offline_refresh']);

        $shop->refreshOfflineAccessTokenIfNeeded();

        $this->assertNull($shop->apiHelper);
        $shop->refresh();
        $this->assertSame('shpat_after_refresh', $shop->getAccessToken()->toNative());

        $shop->api();
        $this->assertSame(
            'shpat_after_refresh',
            $shop->api()->getSession()->getAccessToken()
        );
    }

    public function testResetApiClientForcesRebuildOnNextApiCall(): void
    {
        $this->configureExpiringTokens();

        $shop = $this->createShopWithExpiringToken('shpat_first_build');
        $firstHelper = $shop->apiHelper();

        $shop->resetApiClient();

        $this->assertNull($shop->apiHelper);

        $secondHelper = $shop->apiHelper();

        $this->assertNotSame($firstHelper, $secondHelper);
    }
}
