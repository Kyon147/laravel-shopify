<?php

namespace Osiset\ShopifyApp\Actions;

use Illuminate\Support\Arr;
use Osiset\ShopifyApp\Contracts\Objects\Values\ShopId as ShopIdValue;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Contracts\ShopModel as IShopModel;
use Osiset\ShopifyApp\Util;

class AfterAuthorize
{
    public function __construct(protected IShopQuery $shopQuery)
    {
    }

    /**
     * TODO: Rethrow an API exception.
     */
    public function __invoke(ShopIdValue $shopId): bool
    {
        $fireJob = function (array $config, IShopModel $shop): bool {
            $job = Arr::get($config, 'job');

            if (Arr::get($config, 'inline', false)) {
                $job::dispatchSync($shop);
            } else {
                $job::dispatch($shop)
                    ->onConnection(Util::getShopifyConfig('job_connections')['after_authenticate'])
                    ->onQueue(Util::getShopifyConfig('job_queues')['after_authenticate']);
            }

            return true;
        };

        $shop = $this->shopQuery->getById($shopId);
        $jobsConfig = Util::getShopifyConfig('after_authenticate_job');

        if (Arr::has($jobsConfig, 0)) {
            foreach ($jobsConfig as $jobConfig) {
                $fireJob($jobConfig, $shop);
            }

            return true;
        } elseif (Arr::has($jobsConfig, 'job')) {
            return $fireJob($jobsConfig, $shop);
        }

        return false;
    }
}
