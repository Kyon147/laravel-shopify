<?php

namespace Osiset\ShopifyApp\Actions;

use Osiset\ShopifyApp\Contracts\Objects\Values\ShopId as ShopIdValue;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Util;

class DispatchScripts
{
    public function __construct(
        protected IShopQuery $shopQuery,
        protected string $jobClass
    ) {
    }

    public function __invoke(ShopIdValue $shopId, bool $inline = false): bool
    {
        $shop = $this->shopQuery->getById($shopId);
        $scripttags = Util::getShopifyConfig('scripttags');

        if (count($scripttags) === 0) {
            return false;
        }

        if ($inline) {
            ($this->jobClass)::dispatchSync(
                $shop->getId(),
                $scripttags
            );
        } else {
            ($this->jobClass)::dispatch(
                $shop->getId(),
                $scripttags
            )->onConnection(Util::getShopifyConfig('job_connections')['scripttags'])
                ->onQueue(Util::getShopifyConfig('job_queues')['scripttags']);
        }

        return true;
    }
}
