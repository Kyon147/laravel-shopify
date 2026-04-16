<?php

namespace Osiset\ShopifyApp\Actions;

use Gnikyt\BasicShopifyAPI\ResponseAccess;
use Osiset\ShopifyApp\Contracts\Objects\Values\ShopId as ShopIdValue;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;

class CreateWebhooks
{
    public function __construct(protected IShopQuery $shopQuery)
    {
    }

    /**
     * TODO: Rethrow an API exception.
     */
    public function __invoke(ShopIdValue $shopId, array $configWebhooks): array
    {
        $shop = $this->shopQuery->getById($shopId);
        $apiHelper = $shop->apiHelper();
        $webhooks = $apiHelper->getWebhooks();

        $created = [];
        $deleted = [];
        $used = [];

        foreach ($configWebhooks as $webhook) {
            if (! $this->checkExists($webhook, $webhooks)) {
                $apiHelper->createWebhook($webhook);
                $created[] = $webhook;
            }

            $used[] = $webhook['address'];
        }

        foreach (data_get($webhooks, 'data.webhookSubscriptions.container.edges', []) as $webhook) {
            if (! in_array(data_get($webhook, 'node.endpoint.callbackUrl'), $used)) {
                $apiHelper->deleteWebhook(data_get($webhook, 'node.id'));
                $deleted[] = $webhook;
            }
        }

        return [
            'created' => $created,
            'deleted' => $deleted,
        ];
    }

    private function checkExists(array $webhook, ResponseAccess $webhooks)
    {
        foreach (data_get($webhooks, 'data.webhookSubscriptions.container.edges', []) as $shopWebhook) {
            if (data_get($shopWebhook, 'node.endpoint.callbackUrl') === $webhook['address']) {
                return true;
            }
        }

        return false;
    }
}
