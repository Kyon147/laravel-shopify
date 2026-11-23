<?php

namespace Osiset\ShopifyApp\Actions;

use Gnikyt\BasicShopifyAPI\ResponseAccess;
use Osiset\ShopifyApp\Contracts\Objects\Values\ShopId as ShopIdValue;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Exceptions\ApiException;

class CreateWebhooks
{
    public function __construct(protected IShopQuery $shopQuery)
    {
    }

    /**
     * Create the configured webhooks that are missing and delete the ones no longer configured.
     *
     * Every configured topic is attempted, so one refused topic does not keep the others from
     * being registered. If any is refused, nothing is deleted (an unused subscription may be
     * the one a refused topic was meant to replace) and one exception lists every refusal.
     *
     * @throws ApiException When Shopify refuses one or more of the webhooks.
     */
    public function __invoke(ShopIdValue $shopId, array $configWebhooks): array
    {
        $shop = $this->shopQuery->getById($shopId);
        $apiHelper = $shop->apiHelper();
        $webhooks = $apiHelper->getWebhooks();

        $created = [];
        $deleted = [];
        $used = [];
        $failed = [];

        foreach ($configWebhooks as $webhook) {
            if (! $this->checkExists($webhook, $webhooks)) {
                try {
                    $apiHelper->createWebhook($webhook);
                    $created[] = $webhook;
                } catch (ApiException $e) {
                    $failed[] = "{$webhook['topic']}: {$e->getMessage()}";
                }
            }

            $used[] = $webhook['address'];
        }

        if ($failed !== []) {
            throw new ApiException(
                sprintf(
                    'Failed to create %d of %d webhooks, no webhooks were deleted: %s',
                    count($failed),
                    count($configWebhooks),
                    implode(' | ', $failed)
                )
            );
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
