<?php

namespace Osiset\ShopifyApp\Test\Console;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Osiset\ShopifyApp\Messaging\Jobs\RefreshShopOfflineTokenJob;
use Osiset\ShopifyApp\Test\Stubs\Api as ApiStub;
use Osiset\ShopifyApp\Test\TestCase;

class RefreshExpiringOfflineTokensCommandTest extends TestCase
{
    public function testDispatchesJobsForShopsWithinRenewalWindow(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);
        $this->app['config']->set('shopify-app.offline_refresh_token_renewal_days', 14);

        factory($this->model)->create([
            'password' => 'shpat_one',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_one'),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(7),
        ]);
        factory($this->model)->create([
            'password' => 'shpat_two',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_two'),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(30),
        ]);

        $this
            ->artisan('shopify-app:refresh-expiring-offline-tokens')
            ->expectsOutput('Dispatched 1 renewal job(s).')
            ->assertExitCode(0);

        Queue::assertPushed(RefreshShopOfflineTokenJob::class, 1);
    }

    public function testDryRunDoesNotDispatchJobs(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        factory($this->model)->create([
            'password' => 'shpat_one',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_one'),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(7),
        ]);

        $this
            ->artisan('shopify-app:refresh-expiring-offline-tokens --dry-run')
            ->expectsOutput('Dry run — 1 shop(s) would be renewed.')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function testFailsWhenFeatureDisabled(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', false);

        factory($this->model)->create([
            'password' => 'shpat_one',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_one'),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(7),
        ]);

        $this
            ->artisan('shopify-app:refresh-expiring-offline-tokens')
            ->expectsOutput('expiring_offline_tokens is disabled. Set SHOPIFY_EXPIRING_OFFLINE_TOKENS=true first.')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    public function testShopOptionDispatchesSingleJob(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        factory($this->model)->create([
            'name' => 'target.myshopify.com',
            'password' => 'shpat_one',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_one'),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(7),
        ]);
        factory($this->model)->create([
            'name' => 'other.myshopify.com',
            'password' => 'shpat_two',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_two'),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(7),
        ]);

        $this
            ->artisan('shopify-app:refresh-expiring-offline-tokens --shop=target.myshopify.com')
            ->expectsOutput('Dispatched 1 renewal job(s).')
            ->assertExitCode(0);

        Queue::assertPushed(RefreshShopOfflineTokenJob::class, 1);
    }

    public function testReportsWhenNoShopsNeedRenewal(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        $this
            ->artisan('shopify-app:refresh-expiring-offline-tokens')
            ->expectsOutput('No shops need renewal.')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function testCommandContinuesAfterOneShopFails(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);
        $this->app['config']->set('queue.default', 'sync');

        $shopFailing = factory($this->model)->create([
            'password' => 'shpat_failing',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_failing'),
            'shopify_offline_access_token_expires_at' => Carbon::now()->subMinutes(5),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(7),
        ]);
        $shopSucceeding = factory($this->model)->create([
            'password' => 'shpat_succeeding',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_succeeding'),
            'shopify_offline_access_token_expires_at' => Carbon::now()->subMinutes(5),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(7),
        ]);

        $this->setApiStub();
        ApiStub::stubResponses(['oauth_offline_refresh_invalid', 'oauth_offline_refresh']);

        $this->artisan('shopify-app:refresh-expiring-offline-tokens')
            ->expectsOutputToContain($shopFailing->name)
            ->expectsOutput('Dispatched 1 renewal job(s). 1 shop(s) failed and were skipped.')
            ->assertExitCode(0);

        $shopFailing->refresh();
        $this->assertSame('shpat_failing', $shopFailing->getAccessToken()->toNative());

        $shopSucceeding->refresh();
        $this->assertSame('shpat_after_refresh', $shopSucceeding->getAccessToken()->toNative());
    }

    public function testDaysOptionIsHonoredInJob(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);
        $this->app['config']->set('shopify-app.offline_refresh_token_renewal_days', 14);
        $this->app['config']->set('queue.default', 'sync');

        $shop = factory($this->model)->create([
            'password' => 'shpat_old',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_old'),
            'shopify_offline_access_token_expires_at' => Carbon::now()->addHour(),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(20),
        ]);

        $this->setApiStub();
        ApiStub::stubResponses(['oauth_offline_refresh']);

        $this->artisan('shopify-app:refresh-expiring-offline-tokens --days=30')
            ->expectsOutput('Dispatched 1 renewal job(s).')
            ->assertExitCode(0);

        $shop->refresh();
        $this->assertSame('shpat_after_refresh', $shop->getAccessToken()->toNative());
    }

    public function testDispatchesJobsToConfiguredQueue(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);
        $this->app['config']->set('shopify-app.job_queues', [
            'refresh_expiring_offline_tokens' => 'shopify-tokens',
        ]);

        factory($this->model)->create([
            'password' => 'shpat_one',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_one'),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(7),
        ]);

        $this
            ->artisan('shopify-app:refresh-expiring-offline-tokens')
            ->expectsOutput('Dispatched 1 renewal job(s).')
            ->assertExitCode(0);

        Queue::assertPushed(RefreshShopOfflineTokenJob::class, function ($job) {
            return $job->queue === 'shopify-tokens';
        });
    }

    public function testDispatchesJobsToConfiguredConnection(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);
        $this->app['config']->set('shopify-app.job_connections', [
            'refresh_expiring_offline_tokens' => 'custom_connection',
        ]);

        factory($this->model)->create([
            'password' => 'shpat_one',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_one'),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(7),
        ]);

        $this
            ->artisan('shopify-app:refresh-expiring-offline-tokens')
            ->expectsOutput('Dispatched 1 renewal job(s).')
            ->assertExitCode(0);

        Queue::assertPushed(RefreshShopOfflineTokenJob::class, function ($job) {
            return $job->connection === 'custom_connection';
        });
    }

    public function testQueueOptionOverridesConfiguredQueue(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);
        $this->app['config']->set('shopify-app.job_queues', [
            'refresh_expiring_offline_tokens' => 'from-config',
        ]);

        factory($this->model)->create([
            'password' => 'shpat_one',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_one'),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(7),
        ]);

        $this
            ->artisan('shopify-app:refresh-expiring-offline-tokens --queue=from-cli')
            ->expectsOutput('Dispatched 1 renewal job(s).')
            ->assertExitCode(0);

        Queue::assertPushed(RefreshShopOfflineTokenJob::class, function ($job) {
            return $job->queue === 'from-cli';
        });
    }

    public function testDispatchesJobsWithoutQueueOrConnectionByDefault(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        factory($this->model)->create([
            'password' => 'shpat_one',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_one'),
            'shopify_offline_refresh_token_expires_at' => Carbon::now()->addDays(7),
        ]);

        $this
            ->artisan('shopify-app:refresh-expiring-offline-tokens')
            ->expectsOutput('Dispatched 1 renewal job(s).')
            ->assertExitCode(0);

        Queue::assertPushed(RefreshShopOfflineTokenJob::class, function ($job) {
            return $job->queue === null && $job->connection === null;
        });
    }
}
