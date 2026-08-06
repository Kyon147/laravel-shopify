<?php

namespace Osiset\ShopifyApp\Test\Messaging\Jobs;

use Illuminate\Support\Facades\Crypt;
use Osiset\ShopifyApp\Messaging\Jobs\MigrateShopTokenJob;
use Osiset\ShopifyApp\Test\Stubs\Api as ApiStub;
use Osiset\ShopifyApp\Test\TestCase;

class MigrateShopTokenJobTest extends TestCase
{
    public function testMigratesLegacyShopViaTokenExchange(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        $shop = factory($this->model)->create([
            'password' => 'shpat_legacy_token',
            'shopify_offline_refresh_token' => null,
        ]);

        $this->setApiStub();
        ApiStub::stubResponses(['access_token_expiring']);

        MigrateShopTokenJob::dispatchSync($shop);

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

    public function testSkipsAlreadyMigratedShopWithoutError(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        $shop = factory($this->model)->create([
            'password' => 'shpat_token',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_existing'),
        ]);

        MigrateShopTokenJob::dispatchSync($shop);

        $shop->refresh();

        $this->assertSame('shpat_token', $shop->getAccessToken()->toNative());
        $this->assertSame(
            'shprt_existing',
            Crypt::decryptString($shop->shopify_offline_refresh_token)
        );
    }
}
