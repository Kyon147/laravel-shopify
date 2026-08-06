<?php

namespace Osiset\ShopifyApp\Test\Messaging\Jobs;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Osiset\ShopifyApp\Messaging\Jobs\RefreshShopOfflineTokenJob;
use Osiset\ShopifyApp\Test\Stubs\Api as ApiStub;
use Osiset\ShopifyApp\Test\TestCase;

class RefreshShopOfflineTokenJobTest extends TestCase
{
    public function testRenewsShopWithinRefreshTokenWindow(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        $shop = factory($this->model)->create([
            'password' => 'shpat_old',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_old'),
            'shopify_offline_access_token_expires_at' => Carbon::now()->subMinutes(5),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(7),
        ]);

        $this->setApiStub();
        ApiStub::stubResponses(['oauth_offline_refresh']);

        RefreshShopOfflineTokenJob::dispatchSync($shop);

        $shop->refresh();

        $this->assertSame('shpat_after_refresh', $shop->getAccessToken()->toNative());
        $this->assertSame(
            'shprt_after_refresh',
            Crypt::decryptString($shop->shopify_offline_refresh_token)
        );
        $this->assertNotNull($shop->shopify_offline_access_token_expires_at);
        $this->assertNotNull($shop->shopify_offline_refresh_token_expires_at);
    }
}
