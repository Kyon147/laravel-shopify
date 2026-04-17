<?php

namespace Osiset\ShopifyApp\Test\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Osiset\ShopifyApp\Services\OfflineAccessTokenRefresher;
use Osiset\ShopifyApp\Test\Stubs\Api as ApiStub;
use Osiset\ShopifyApp\Test\TestCase;

class OfflineAccessTokenRefresherTest extends TestCase
{
    public function testRefreshesExpiredOfflineAccessTokenBeforeApiHelper(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        $shop = factory($this->model)->create([
            'password' => 'shpat_old',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_old'),
            'shopify_offline_access_token_expires_at' => Carbon::now()->subMinutes(5),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(60),
        ]);

        $this->setApiStub();
        ApiStub::stubResponses(['oauth_offline_refresh']);

        $shop->apiHelper();

        $shop->refresh();

        $this->assertSame('shpat_after_refresh', $shop->getAccessToken()->toNative());
        $this->assertSame(
            'shprt_after_refresh',
            Crypt::decryptString($shop->shopify_offline_refresh_token)
        );
    }

    public function testDoesNothingWhenFeatureDisabled(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', false);

        $shop = factory($this->model)->create([
            'password' => 'shpat_old',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_old'),
            'shopify_offline_access_token_expires_at' => Carbon::now()->subMinutes(5),
        ]);

        $this->setApiStub();
        ApiStub::stubResponses(['oauth_offline_refresh']);

        app(OfflineAccessTokenRefresher::class)->refreshIfNeeded($shop);

        $shop->refresh();

        $this->assertSame('shpat_old', $shop->getAccessToken()->toNative());
    }
}