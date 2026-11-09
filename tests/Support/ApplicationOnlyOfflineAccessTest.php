<?php

namespace Osiset\ShopifyApp\Test\Support;

use Illuminate\Support\Facades\Crypt;
use Osiset\ShopifyApp\Support\ApplicationOnlyOfflineAccess;
use Osiset\ShopifyApp\Test\TestCase;

class ApplicationOnlyOfflineAccessTest extends TestCase
{
    public function testDetectsApplicationOnlySentinelRefreshToken(): void
    {
        $this->app['config']->set(
            'shopify-app.application_only_refresh_sentinel',
            'shopify-app:application-only-offline-access-v1',
        );

        $shop = factory($this->model)->create([
            'shopify_offline_refresh_token' => Crypt::encryptString(
                ApplicationOnlyOfflineAccess::refreshTokenSentinel(),
            ),
        ]);

        $this->assertTrue(ApplicationOnlyOfflineAccess::shopUses($shop));
        $this->assertTrue($shop->usesApplicationOnlyOfflineAccess());
    }

    public function testDoesNotTreatRealRefreshTokenAsApplicationOnly(): void
    {
        $shop = factory($this->model)->create([
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_real_refresh'),
        ]);

        $this->assertFalse(ApplicationOnlyOfflineAccess::shopUses($shop));
    }

    public function testApplicationOnlyShopsAreIneligibleForScheduledRefreshTokenRenewal(): void
    {
        $this->app['config']->set(
            'shopify-app.application_only_refresh_sentinel',
            'shopify-app:application-only-offline-access-v1',
        );

        $applicationOnly = factory($this->model)->create([
            'shopify_offline_refresh_token' => Crypt::encryptString(
                ApplicationOnlyOfflineAccess::refreshTokenSentinel(),
            ),
        ]);
        $refreshShop = factory($this->model)->create([
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_real_refresh'),
        ]);

        $this->assertFalse(
            ApplicationOnlyOfflineAccess::shopEligibleForScheduledRefreshTokenRenewal($applicationOnly),
        );
        $this->assertTrue(
            ApplicationOnlyOfflineAccess::shopEligibleForScheduledRefreshTokenRenewal($refreshShop),
        );
    }
}
