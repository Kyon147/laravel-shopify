<?php

namespace Osiset\ShopifyApp\Console;

use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\PendingDispatch;
use Osiset\ShopifyApp\Messaging\Jobs\MigrateShopTokenJob;
use Osiset\ShopifyApp\Util;

class MigrateExpiringOfflineTokensCommand extends Command
{
    protected $signature = 'shopify-app:migrate-expiring-offline-tokens
        {--shop= : Migrate a single shop by domain (e.g. example.myshopify.com)}
        {--dry-run : List shops that would be migrated without dispatching jobs}
        {--queue= : Queue name for dispatched jobs (overrides config)}
        {--connection= : Queue connection for dispatched jobs (overrides config)}';

    protected $description = 'Dispatch queue jobs to migrate legacy non-expiring offline tokens to expiring offline tokens (optional; requires SHOPIFY_EXPIRING_OFFLINE_TOKENS)';

    public function handle(): int
    {
        if (! Util::getShopifyConfig('expiring_offline_tokens')) {
            $this->error('expiring_offline_tokens is disabled. Set SHOPIFY_EXPIRING_OFFLINE_TOKENS=true first.');

            return self::FAILURE;
        }

        $modelClass = Util::getShopifyConfig('user_model');
        $shopDomain = $this->option('shop');

        $query = $modelClass::query()
            ->whereNotNull('password')
            ->where('password', '!=', '')
            ->where(function ($q) {
                $q->whereNull('shopify_offline_refresh_token')
                    ->orWhere('shopify_offline_refresh_token', '');
            });

        if ($shopDomain) {
            $query->where('name', $shopDomain);
        }

        if ($this->option('dry-run')) {
            $count = 0;
            $query->chunkById(100, function ($shops) use (&$count) {
                foreach ($shops as $shop) {
                    $this->line('  - '.$shop->name.' (id: '.$shop->id.')');
                    $count++;
                }
            });

            if ($count === 0) {
                $this->info('No shops need migration.');
            } else {
                $this->info("Dry run — {$count} shop(s) would be migrated.");
            }

            return self::SUCCESS;
        }

        $dispatched = 0;
        $failed = 0;

        $query->chunkById(100, function ($shops) use (&$dispatched, &$failed) {
            foreach ($shops as $shop) {
                try {
                    $this->configureJobDispatch(MigrateShopTokenJob::dispatch($shop));
                    $dispatched++;
                } catch (\Throwable $e) {
                    $this->warn("  [FAILED] {$shop->name}: {$e->getMessage()}");
                    $failed++;
                }
            }
        });

        if ($dispatched === 0 && $failed === 0) {
            $this->info('No shops need migration.');

            return self::SUCCESS;
        }

        $this->info("Dispatched {$dispatched} migration job(s).".($failed > 0 ? " {$failed} shop(s) failed and were skipped." : ''));

        return self::SUCCESS;
    }

    /**
     * Apply queue/connection from CLI options or config to a pending dispatch.
     */
    protected function configureJobDispatch(PendingDispatch $dispatch): PendingDispatch
    {
        $connection = $this->option('connection')
            ?: (Util::getShopifyConfig('job_connections')['migrate_expiring_offline_tokens'] ?? null);
        $queue = $this->option('queue')
            ?: (Util::getShopifyConfig('job_queues')['migrate_expiring_offline_tokens'] ?? null);

        if ($connection) {
            $dispatch->onConnection($connection);
        }

        if ($queue) {
            $dispatch->onQueue($queue);
        }

        return $dispatch;
    }
}
