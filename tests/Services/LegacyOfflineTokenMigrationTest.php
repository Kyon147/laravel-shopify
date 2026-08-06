<?php

namespace Osiset\ShopifyApp\Test\Services;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Mockery;
use Osiset\ShopifyApp\Actions\MigrateShopToExpiringOfflineAccessToken;
use Osiset\ShopifyApp\Test\Stubs\Api as ApiStub;
use Osiset\ShopifyApp\Test\TestCase;
use RuntimeException;

class LegacyOfflineTokenMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testLazyMigrationRunsBeforeApiHelper(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);
        $this->app['config']->set('shopify-app.auto_migrate_legacy', true);

        $shop = factory($this->model)->create([
            'password' => 'shpat_legacy_token',
            'shopify_offline_refresh_token' => null,
        ]);

        $this->setApiStub();
        ApiStub::stubResponses(['access_token_expiring']);

        $shop->apiHelper();

        $shop->refresh();

        $this->assertSame('shpat_expiring_test_token', $shop->getAccessToken()->toNative());
        $this->assertNotNull($shop->shopify_offline_refresh_token);
        $this->assertSame(
            'shprt_expiring_test_refresh',
            Crypt::decryptString($shop->shopify_offline_refresh_token)
        );
    }

    public function testLazyMigrationSkippedWhenAutoMigrateDisabled(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);
        $this->app['config']->set('shopify-app.auto_migrate_legacy', false);

        $shop = factory($this->model)->create([
            'password' => 'shpat_legacy_token',
            'shopify_offline_refresh_token' => null,
        ]);

        $this->setApiStub();
        ApiStub::stubResponses(['access_token_expiring']);

        $shop->apiHelper();

        $shop->refresh();

        $this->assertSame('shpat_legacy_token', $shop->getAccessToken()->toNative());
        $this->assertNull($shop->shopify_offline_refresh_token);
    }

    public function testLazyMigrationFailsOpenOnException(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);
        $this->app['config']->set('shopify-app.auto_migrate_legacy', true);

        $shop = factory($this->model)->create([
            'password' => 'shpat_legacy_token',
            'shopify_offline_refresh_token' => null,
        ]);

        $mock = Mockery::mock(MigrateShopToExpiringOfflineAccessToken::class);
        $mock->shouldReceive('__invoke')
            ->once()
            ->with($shop)
            ->andThrow(new RuntimeException('Shopify network error'));
        $this->app->instance(MigrateShopToExpiringOfflineAccessToken::class, $mock);

        $warnings = [];
        Event::listen(function (MessageLogged $event) use (&$warnings) {
            $warnings[] = $event;
        });

        $this->setApiStub();

        $helper = $shop->apiHelper();

        $shop->refresh();

        $this->assertSame('shpat_legacy_token', $shop->getAccessToken()->toNative());
        $this->assertNull($shop->shopify_offline_refresh_token);
        $this->assertNotNull($helper);

        $matching = array_filter($warnings, function (MessageLogged $event) {
            return $event->level === 'warning'
                && str_contains($event->message, 'On-the-fly Shopify offline token migration failed.')
                && ($event->context['message'] ?? '') === 'Shopify network error';
        });
        $this->assertCount(1, $matching);
    }
}
