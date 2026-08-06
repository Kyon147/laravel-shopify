<?php

namespace Osiset\ShopifyApp\Test\Actions;

use Illuminate\Support\Facades\Crypt;
use Osiset\ShopifyApp\Actions\MigrateShopToExpiringOfflineAccessToken;
use Osiset\ShopifyApp\Test\Stubs\Api as ApiStub;
use Osiset\ShopifyApp\Test\TestCase;

class MigrateShopToExpiringOfflineAccessTokenTest extends TestCase
{
    public function testMigratesShopWithLegacyOfflineToken(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        $shop = factory($this->model)->create([
            'password' => 'shpat_legacy_token',
            'shopify_offline_refresh_token' => null,
        ]);

        $this->setApiStub();
        ApiStub::stubResponses(['access_token_expiring']);

        $result = $this->app->make(MigrateShopToExpiringOfflineAccessToken::class)($shop);

        $this->assertTrue($result['migrated']);
        $this->assertFalse($result['skipped']);
        $this->assertNull($result['error']);

        $shop->refresh();
        $this->assertSame('shpat_expiring_test_token', $shop->getAccessToken()->toNative());
        $this->assertNotNull($shop->shopify_offline_refresh_token);
        $this->assertSame(
            'shprt_expiring_test_refresh',
            Crypt::decryptString($shop->shopify_offline_refresh_token)
        );
    }

    public function testSkipsWhenAlreadyOnExpiringTokens(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        $shop = factory($this->model)->create([
            'password' => 'shpat_token',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_existing'),
        ]);

        $result = $this->app->make(MigrateShopToExpiringOfflineAccessToken::class)($shop);

        $this->assertFalse($result['migrated']);
        $this->assertTrue($result['skipped']);
        $this->assertSame(MigrateShopToExpiringOfflineAccessToken::REASON_ALREADY_EXPIRING, $result['reason']);
    }

    public function testSkipsWhenFeatureDisabled(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', false);

        $shop = factory($this->model)->create(['password' => 'shpat_token']);

        $result = $this->app->make(MigrateShopToExpiringOfflineAccessToken::class)($shop);

        $this->assertTrue($result['skipped']);
        $this->assertSame(MigrateShopToExpiringOfflineAccessToken::REASON_FEATURE_DISABLED, $result['reason']);
    }
}
