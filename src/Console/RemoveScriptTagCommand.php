<?php

namespace Osiset\ShopifyApp\Console;

use Illuminate\Console\Command;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Exceptions\InvalidShopDomainException;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;

class RemoveScriptTagCommand extends Command
{
    protected $signature = 'shopify-app:remove-script-tag
        {domain : Shop domain (e.g. example.myshopify.com)}
        {--id= : Only remove the script tag with this ID}
        {--dry-run : List script tags that would be removed without deleting them}';

    protected $description = 'Remove script tags installed by the app from a shop (script tags can no longer be created or updated)';

    public function handle(IShopQuery $shopQuery): int
    {
        $domain = $this->argument('domain');

        try {
            $shop = $shopQuery->getByDomain(ShopDomain::fromNative($domain));
        } catch (InvalidShopDomainException $e) {
            $shop = null;
        }

        if ($shop === null) {
            $this->error("Shop {$domain} not found.");

            return self::FAILURE;
        }

        $api = $shop->apiHelper();
        $tags = collect($api->getScriptTags()->toArray());

        $id = $this->option('id');
        if ($id !== null) {
            $tags = $tags->filter(fn (array $tag) => (string) $tag['id'] === (string) $id);

            if ($tags->isEmpty()) {
                $this->error("Script tag {$id} not found for {$domain}.");

                return self::FAILURE;
            }
        }

        if ($tags->isEmpty()) {
            $this->info("No script tags found for {$domain}.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($tags as $tag) {
                $this->line("  - {$tag['id']} ({$tag['src']})");
            }

            $this->info("Dry run — {$tags->count()} script tag(s) would be removed from {$domain}.");

            return self::SUCCESS;
        }

        $removed = 0;
        $failed = 0;

        foreach ($tags as $tag) {
            try {
                $api->deleteScriptTag((int) $tag['id']);
                $this->line("  - Removed {$tag['id']} ({$tag['src']})");
                $removed++;
            } catch (\Throwable $e) {
                $this->warn("  [FAILED] {$tag['id']}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Removed {$removed} script tag(s) from {$domain}.".($failed > 0 ? " {$failed} failed." : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
