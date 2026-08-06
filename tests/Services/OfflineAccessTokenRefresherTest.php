<?php

namespace Osiset\ShopifyApp\Test\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Mockery;
use Osiset\ShopifyApp\Exceptions\OAuthTokenRefreshException;
use Osiset\ShopifyApp\Services\OfflineAccessTokenRefresher;
use Osiset\ShopifyApp\Test\Stubs\Api as ApiStub;
use Osiset\ShopifyApp\Test\TestCase;

class OfflineAccessTokenRefresherTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

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

    public function testSkipsNetworkWhenAccessTokenAlreadyFreshInsideLock(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        $shop = factory($this->model)->create([
            'password' => 'shpat_old',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_old'),
            'shopify_offline_access_token_expires_at' => Carbon::now()->subMinutes(5),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(60),
        ]);

        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')
            ->once()
            ->with(10, Mockery::on(function ($callback) use ($shop) {
                DB::table('users')
                    ->where('id', $shop->id)
                    ->update([
                        'shopify_offline_access_token_expires_at' => Carbon::now()->addHour()->format('Y-m-d H:i:s'),
                    ]);
                $callback();

                return true;
            }))
            ->andReturn(true);

        Cache::shouldReceive('lock')
            ->once()
            ->with('shopify-offline-token:'.$shop->id, 30)
            ->andReturn($lock);

        $this->setApiStub();
        ApiStub::stubResponses(['oauth_offline_refresh']);

        app(OfflineAccessTokenRefresher::class)->refreshIfNeeded($shop);

        $shop->refresh();
        $this->assertSame('shpat_old', $shop->getAccessToken()->toNative());
        $this->assertSame(['oauth_offline_refresh'], ApiStub::$stubFiles);
    }

    public function testReturnsEarlyWhenRefreshTokenClearedInsideLock(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        $shop = factory($this->model)->create([
            'password' => 'shpat_old',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_old'),
            'shopify_offline_access_token_expires_at' => Carbon::now()->subMinutes(5),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(60),
        ]);

        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')
            ->once()
            ->with(10, Mockery::on(function ($callback) use ($shop) {
                DB::table('users')
                    ->where('id', $shop->id)
                    ->update(['shopify_offline_refresh_token' => null]);
                $callback();

                return true;
            }))
            ->andReturn(true);

        Cache::shouldReceive('lock')
            ->once()
            ->with('shopify-offline-token:'.$shop->id, 30)
            ->andReturn($lock);

        $this->setApiStub();
        ApiStub::stubResponses(['oauth_offline_refresh']);

        app(OfflineAccessTokenRefresher::class)->refreshIfNeeded($shop);

        $shop->refresh();
        $this->assertSame('shpat_old', $shop->getAccessToken()->toNative());
        $this->assertSame(['oauth_offline_refresh'], ApiStub::$stubFiles);
    }

    public function testThrowsWhenRefreshTokenCannotBeDecrypted(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        $shop = factory($this->model)->create([
            'password' => 'shpat_old',
            'shopify_offline_refresh_token' => 'not-valid-encrypted-payload',
            'shopify_offline_access_token_expires_at' => Carbon::now()->subMinutes(5),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(60),
        ]);

        $this->expectException(OAuthTokenRefreshException::class);
        $this->expectExceptionMessage('Unable to decrypt offline refresh token.');

        app(OfflineAccessTokenRefresher::class)->refreshIfNeeded($shop);
    }

    public function testThrowsWhenShopifyReturnsInvalidRefreshPayload(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        $shop = factory($this->model)->create([
            'password' => 'shpat_old',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_old'),
            'shopify_offline_access_token_expires_at' => Carbon::now()->subMinutes(5),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(60),
        ]);

        $this->setApiStub();
        ApiStub::stubResponses(['oauth_offline_refresh_invalid']);

        $this->expectException(OAuthTokenRefreshException::class);
        $this->expectExceptionMessage('Invalid token refresh response from Shopify.');

        app(OfflineAccessTokenRefresher::class)->refreshIfNeeded($shop);
    }

    public function testOfflineAccessTokenNeedsRefreshFalseWhenExpiryMissing(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        $shop = factory($this->model)->create([
            'password' => 'shpat_old',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_old'),
            'shopify_offline_access_token_expires_at' => null,
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(60),
        ]);

        $this->assertFalse(app(OfflineAccessTokenRefresher::class)->offlineAccessTokenNeedsRefresh($shop));
    }

    public function testOfflineAccessTokenNeedsRefreshTrueWhenExpired(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        $shop = factory($this->model)->create([
            'password' => 'shpat_old',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_old'),
            'shopify_offline_access_token_expires_at' => Carbon::now()->subMinutes(5),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(60),
        ]);

        $this->assertTrue(app(OfflineAccessTokenRefresher::class)->offlineAccessTokenNeedsRefresh($shop));
    }

    public function testOfflineAccessTokenNeedsRefreshFalseWhenStillFresh(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        $shop = factory($this->model)->create([
            'password' => 'shpat_old',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_old'),
            'shopify_offline_access_token_expires_at' => Carbon::now()->addHour(),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(60),
        ]);

        $this->assertFalse(app(OfflineAccessTokenRefresher::class)->offlineAccessTokenNeedsRefresh($shop));
    }

    public function testOfflineRefreshTokenNeedsRenewalTrueWhenWithinWindow(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);
        $this->app['config']->set('shopify-app.offline_refresh_token_renewal_days', 14);

        $shop = factory($this->model)->create([
            'password' => 'shpat_old',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_old'),
            'shopify_offline_access_token_expires_at' => Carbon::now()->addHour(),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(7),
        ]);

        $this->assertTrue(app(OfflineAccessTokenRefresher::class)->offlineRefreshTokenNeedsRenewal($shop));
    }

    public function testOfflineRefreshTokenNeedsRenewalFalseWhenOutsideWindow(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);
        $this->app['config']->set('shopify-app.offline_refresh_token_renewal_days', 14);

        $shop = factory($this->model)->create([
            'password' => 'shpat_old',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_old'),
            'shopify_offline_access_token_expires_at' => Carbon::now()->addHour(),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(30),
        ]);

        $this->assertFalse(app(OfflineAccessTokenRefresher::class)->offlineRefreshTokenNeedsRenewal($shop));
    }

    public function testOfflineRefreshTokenNeedsRenewalFalseWhenAlreadyExpired(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);
        $this->app['config']->set('shopify-app.offline_refresh_token_renewal_days', 14);

        $shop = factory($this->model)->create([
            'password' => 'shpat_old',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_old'),
            'shopify_offline_access_token_expires_at' => Carbon::now()->addHour(),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->subDay(),
        ]);

        $this->assertFalse(app(OfflineAccessTokenRefresher::class)->offlineRefreshTokenNeedsRenewal($shop));
    }

    public function testOfflineRefreshTokenNeedsRenewalFalseWhenFeatureDisabled(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', false);

        $shop = factory($this->model)->create([
            'password' => 'shpat_old',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_old'),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(7),
        ]);

        $this->assertFalse(app(OfflineAccessTokenRefresher::class)->offlineRefreshTokenNeedsRenewal($shop));
    }

    public function testRefreshIfNeededWhenOnlyRefreshTokenWithinWindow(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);
        $this->app['config']->set('shopify-app.offline_refresh_token_renewal_days', 14);

        $shop = factory($this->model)->create([
            'password' => 'shpat_old',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_old'),
            'shopify_offline_access_token_expires_at' => Carbon::now()->addHour(),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(7),
        ]);

        $this->setApiStub();
        ApiStub::stubResponses(['oauth_offline_refresh']);

        app(OfflineAccessTokenRefresher::class)->refreshIfNeeded($shop);

        $shop->refresh();

        $this->assertSame('shpat_after_refresh', $shop->getAccessToken()->toNative());
        $this->assertSame(
            'shprt_after_refresh',
            Crypt::decryptString($shop->shopify_offline_refresh_token)
        );
    }

    public function testOfflineRefreshTokenNeedsRenewalHonorsRenewalDaysOverride(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);
        $this->app['config']->set('shopify-app.offline_refresh_token_renewal_days', 14);

        $shop = factory($this->model)->create([
            'password' => 'shpat_old',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_old'),
            'shopify_offline_access_token_expires_at' => Carbon::now()->addHour(),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(20),
        ]);

        $refresher = app(OfflineAccessTokenRefresher::class);

        $this->assertFalse($refresher->offlineRefreshTokenNeedsRenewal($shop));
        $this->assertTrue($refresher->offlineRefreshTokenNeedsRenewal($shop, 30));
    }
}
