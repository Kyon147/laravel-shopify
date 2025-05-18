<?php

namespace Osiset\ShopifyApp\Actions;

use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Objects\Values\ShopId;

class DeleteWebhooks
{
    public function __construct(protected IShopQuery $shopQuery)
    {
    }

    /**
     * TODO: Rethrow an API exception.
     */
    public function __invoke(ShopId $shopId): array
    {
        $shop = $this->shopQuery->getById($shopId);
        $apiHelper = $shop->apiHelper();
        $webhooks = $apiHelper->getWebhooks();

        $deleted = [];

        foreach (data_get($webhooks, 'data.webhookSubscriptions.container.edges', []) as $webhook) {
            $apiHelper->deleteWebhook(data_get($webhook, 'node.id'));

            $deleted[] = $webhook;
        }

        return $deleted;
    }
}
