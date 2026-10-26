<?php

namespace Osiset\ShopifyApp\Test\Console;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Osiset\ShopifyApp\Messaging\Jobs\MigrateShopTokenJob;
use Osiset\ShopifyApp\Test\Stubs\Api as ApiStub;
use Osiset\ShopifyApp\Test\TestCase;

class MigrateExpiringOfflineTokensCommandTest extends TestCase
{
    public function testDispatchesJobsForLegacyShopsOnly(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        factory($this->model)->create([
            'password' => 'shpat_legacy_one',
            'shopify_offline_refresh_token' => null,
        ]);
        factory($this->model)->create([
            'password' => 'shpat_legacy_two',
            'shopify_offline_refresh_token' => null,
        ]);
        factory($this->model)->create([
            'password' => 'shpat_expiring',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_existing'),
        ]);

        $this
            ->artisan('shopify-app:migrate-expiring-offline-tokens')
            ->expectsOutput('Dispatched 2 migration job(s).')
            ->assertExitCode(0);

        Queue::assertPushed(MigrateShopTokenJob::class, 2);
    }

    public function testDryRunDoesNotDispatchJobs(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        factory($this->model)->create([
            'password' => 'shpat_legacy',
            'shopify_offline_refresh_token' => null,
        ]);

        $this
            ->artisan('shopify-app:migrate-expiring-offline-tokens --dry-run')
            ->expectsOutput('Dry run — 1 shop(s) would be migrated.')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function testFailsWhenFeatureDisabled(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', false);

        factory($this->model)->create([
            'password' => 'shpat_legacy',
            'shopify_offline_refresh_token' => null,
        ]);

        $this
            ->artisan('shopify-app:migrate-expiring-offline-tokens')
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
            'password' => 'shpat_legacy',
            'shopify_offline_refresh_token' => null,
        ]);
        factory($this->model)->create([
            'name' => 'other.myshopify.com',
            'password' => 'shpat_legacy_other',
            'shopify_offline_refresh_token' => null,
        ]);

        $this
            ->artisan('shopify-app:migrate-expiring-offline-tokens --shop=target.myshopify.com')
            ->expectsOutput('Dispatched 1 migration job(s).')
            ->assertExitCode(0);

        Queue::assertPushed(MigrateShopTokenJob::class, 1);
    }

    public function testSkipsShopsWithEmptyPassword(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        factory($this->model)->create([
            'name' => 'empty-password.myshopify.com',
            'password' => '',
            'shopify_offline_refresh_token' => null,
        ]);
        factory($this->model)->create([
            'name' => 'legacy.myshopify.com',
            'password' => 'shpat_legacy',
            'shopify_offline_refresh_token' => null,
        ]);

        $this
            ->artisan('shopify-app:migrate-expiring-offline-tokens')
            ->expectsOutput('Dispatched 1 migration job(s).')
            ->assertExitCode(0);

        Queue::assertPushed(MigrateShopTokenJob::class, 1);
    }

    public function testSkipsExcludedShops(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);
        $this->app['config']->set('shopify-app.offline_token_excluded_shops', [
            'placeholder.myshopify.com',
        ]);

        factory($this->model)->create([
            'name' => 'placeholder.myshopify.com',
            'password' => 'shpat_placeholder',
            'shopify_offline_refresh_token' => null,
        ]);
        factory($this->model)->create([
            'name' => 'legacy.myshopify.com',
            'password' => 'shpat_legacy',
            'shopify_offline_refresh_token' => null,
        ]);

        $this
            ->artisan('shopify-app:migrate-expiring-offline-tokens')
            ->expectsOutput('Dispatched 1 migration job(s).')
            ->assertExitCode(0);

        Queue::assertPushed(MigrateShopTokenJob::class, 1);
    }

    public function testShopOptionDoesNotOverrideExclusion(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);
        $this->app['config']->set('shopify-app.offline_token_excluded_shops', [
            'placeholder.myshopify.com',
        ]);

        factory($this->model)->create([
            'name' => 'placeholder.myshopify.com',
            'password' => 'shpat_placeholder',
            'shopify_offline_refresh_token' => null,
        ]);

        $this
            ->artisan('shopify-app:migrate-expiring-offline-tokens --shop=placeholder.myshopify.com')
            ->expectsOutput('No shops need migration.')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function testDryRunOmitsExcludedShops(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);
        $this->app['config']->set('shopify-app.offline_token_excluded_shops', [
            'placeholder.myshopify.com',
        ]);

        factory($this->model)->create([
            'name' => 'placeholder.myshopify.com',
            'password' => 'shpat_placeholder',
            'shopify_offline_refresh_token' => null,
        ]);
        factory($this->model)->create([
            'name' => 'legacy.myshopify.com',
            'password' => 'shpat_legacy',
            'shopify_offline_refresh_token' => null,
        ]);

        $this
            ->artisan('shopify-app:migrate-expiring-offline-tokens --dry-run')
            ->expectsOutputToContain('legacy.myshopify.com')
            ->expectsOutput('Dry run — 1 shop(s) would be migrated.')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function testSkipsExcludedShopsWhenNameCaseDiffers(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);
        $this->app['config']->set('shopify-app.offline_token_excluded_shops', [
            'placeholder.myshopify.com',
        ]);

        factory($this->model)->create([
            'name' => 'Placeholder.myshopify.com',
            'password' => 'shpat_placeholder',
            'shopify_offline_refresh_token' => null,
        ]);

        $this
            ->artisan('shopify-app:migrate-expiring-offline-tokens')
            ->expectsOutput('No shops need migration.')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function testReportsWhenNoShopsNeedMigration(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        $this
            ->artisan('shopify-app:migrate-expiring-offline-tokens')
            ->expectsOutput('No shops need migration.')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    /**
     * Confirms the fix: the command continues processing remaining shops even when one fails,
     * surfacing a warning for the failing shop and migrating all others.
     *
     * This test fails BEFORE the fix and passes once the fix is applied.
     */
    public function testCommandContinuesAfterOneShopFails(): void
    {
        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);
        $this->app['config']->set('queue.default', 'sync');

        $shopFailing = factory($this->model)->create([
            'password' => 'shpat_stale_token',
            'shopify_offline_refresh_token' => null,
        ]);
        $shopSucceeding = factory($this->model)->create([
            'password' => 'shpat_valid_token',
            'shopify_offline_refresh_token' => null,
        ]);

        $this->setApiStub();
        ApiStub::stubResponses(['oauth_access_token_invalid_subject_token', 'access_token_expiring']);

        $this->artisan('shopify-app:migrate-expiring-offline-tokens')
            ->expectsOutputToContain($shopFailing->name)
            ->expectsOutput('Dispatched 1 migration job(s). 1 shop(s) failed and were skipped.')
            ->assertExitCode(0);

        // Failing shop: token unchanged
        $shopFailing->refresh();
        $this->assertNull($shopFailing->shopify_offline_refresh_token);

        // Succeeding shop: fully migrated
        $shopSucceeding->refresh();
        $this->assertNotNull($shopSucceeding->shopify_offline_refresh_token);
    }

    public function testDispatchesJobsToConfiguredQueue(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);
        $this->app['config']->set('shopify-app.job_queues', [
            'migrate_expiring_offline_tokens' => 'shopify-tokens',
        ]);

        factory($this->model)->create([
            'password' => 'shpat_legacy',
            'shopify_offline_refresh_token' => null,
        ]);

        $this
            ->artisan('shopify-app:migrate-expiring-offline-tokens')
            ->expectsOutput('Dispatched 1 migration job(s).')
            ->assertExitCode(0);

        Queue::assertPushed(MigrateShopTokenJob::class, function ($job) {
            return $job->queue === 'shopify-tokens';
        });
    }

    public function testDispatchesJobsToConfiguredConnection(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);
        $this->app['config']->set('shopify-app.job_connections', [
            'migrate_expiring_offline_tokens' => 'custom_connection',
        ]);

        factory($this->model)->create([
            'password' => 'shpat_legacy',
            'shopify_offline_refresh_token' => null,
        ]);

        $this
            ->artisan('shopify-app:migrate-expiring-offline-tokens')
            ->expectsOutput('Dispatched 1 migration job(s).')
            ->assertExitCode(0);

        Queue::assertPushed(MigrateShopTokenJob::class, function ($job) {
            return $job->connection === 'custom_connection';
        });
    }

    public function testQueueOptionOverridesConfiguredQueue(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);
        $this->app['config']->set('shopify-app.job_queues', [
            'migrate_expiring_offline_tokens' => 'from-config',
        ]);

        factory($this->model)->create([
            'password' => 'shpat_legacy',
            'shopify_offline_refresh_token' => null,
        ]);

        $this
            ->artisan('shopify-app:migrate-expiring-offline-tokens --queue=from-cli')
            ->expectsOutput('Dispatched 1 migration job(s).')
            ->assertExitCode(0);

        Queue::assertPushed(MigrateShopTokenJob::class, function ($job) {
            return $job->queue === 'from-cli';
        });
    }

    public function testDispatchesJobsWithoutQueueOrConnectionByDefault(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        factory($this->model)->create([
            'password' => 'shpat_legacy',
            'shopify_offline_refresh_token' => null,
        ]);

        $this
            ->artisan('shopify-app:migrate-expiring-offline-tokens')
            ->expectsOutput('Dispatched 1 migration job(s).')
            ->assertExitCode(0);

        Queue::assertPushed(MigrateShopTokenJob::class, function ($job) {
            return $job->queue === null && $job->connection === null;
        });
    }
}
