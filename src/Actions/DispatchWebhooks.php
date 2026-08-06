<?php

namespace Osiset\ShopifyApp\Actions;

use Osiset\ShopifyApp\Contracts\Objects\Values\ShopId as ShopIdValue;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Util;

class DispatchWebhooks
{
    public function __construct(
        protected IShopQuery $shopQuery,
        protected string $jobClass
    ) {
    }

    public function __invoke(ShopIdValue $shopId, bool $inline = false): bool
    {
        $webhooks = Util::getShopifyConfig('webhooks');

        if (count($webhooks) === 0) {
            return false;
        }

        $shop = $this->shopQuery->getById($shopId);

        if ($inline) {
            ($this->jobClass)::dispatchSync(
                $shop->getId(),
                $webhooks
            );
        } else {
            ($this->jobClass)::dispatch(
                $shop->getId(),
                $webhooks
            )->onConnection(Util::getShopifyConfig('job_connections')['webhooks'])
                ->onQueue(Util::getShopifyConfig('job_queues')['webhooks']);
        }

        return true;
    }
}
