<?php

namespace Osiset\ShopifyApp\Messaging\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Osiset\ShopifyApp\Actions\MigrateShopToExpiringOfflineAccessToken;
use Osiset\ShopifyApp\Contracts\ShopModel as IShopModel;
use RuntimeException;

/**
 * Queue job to migrate a single shop from a legacy offline token to expiring offline tokens.
 */
class MigrateShopTokenJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param IShopModel $shop
     */
    public function __construct(protected IShopModel $shop)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(MigrateShopToExpiringOfflineAccessToken $migrate): void
    {
        $result = $migrate($this->shop);

        if ($result['error'] !== null) {
            throw new RuntimeException($result['error']);
        }
    }
}
