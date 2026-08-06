<?php

namespace Osiset\ShopifyApp\Messaging\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Osiset\ShopifyApp\Contracts\ShopModel as IShopModel;
use Osiset\ShopifyApp\Services\OfflineAccessTokenRefresher;

/**
 * Queue job to renew expiring offline tokens for a single shop.
 */
class RefreshShopOfflineTokenJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param IShopModel $shop
     * @param int|null   $renewalDays Renewal window in days passed from the Artisan command
     */
    public function __construct(protected IShopModel $shop, protected ?int $renewalDays = null)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(OfflineAccessTokenRefresher $refresher): void
    {
        $refresher->refreshIfNeeded($this->shop, $this->renewalDays);
    }
}
