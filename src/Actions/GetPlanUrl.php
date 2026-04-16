<?php

namespace Osiset\ShopifyApp\Actions;

use Osiset\ShopifyApp\Contracts\Queries\Plan as IPlanQuery;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Objects\Values\NullablePlanId;
use Osiset\ShopifyApp\Objects\Values\ShopId;
use Osiset\ShopifyApp\Services\ChargeHelper;

class GetPlanUrl
{
    public function __construct(
        protected ChargeHelper $chargeHelper,
        protected IPlanQuery $planQuery,
        protected IShopQuery $shopQuery
    ) {
    }

    /**
     * TODO: Rethrow an API exception.
     */
    public function __invoke(ShopId $shopId, NullablePlanId $planId, string $host): string
    {
        $shop = $this->shopQuery->getById($shopId);
        $plan = $planId->isNull() ? $this->planQuery->getDefault() : $this->planQuery->getById($planId);

        // All plan types use GraphQL appSubscriptionCreate — REST recurring_application_charges
        // is rejected for shops with non-expiring (legacy) access tokens.
        $api = $shop->apiHelper()
            ->createChargeGraphQL($this->chargeHelper->details($plan, $shop, $host));

        return $api['confirmationUrl'];
    }
}
