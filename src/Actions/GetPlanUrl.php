<?php

namespace Osiset\ShopifyApp\Actions;

use Osiset\ShopifyApp\Contracts\Queries\Plan as IPlanQuery;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Objects\Enums\ChargeInterval;
use Osiset\ShopifyApp\Objects\Enums\ChargeType;
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

        if ($plan->getInterval()->toNative() === ChargeInterval::ANNUAL()->toNative()) {
            $api = $shop->apiHelper()
                ->createChargeGraphQL($this->chargeHelper->details($plan, $shop, $host));

            $confirmationUrl = $api['confirmationUrl'];
        } else {
            $api = $shop->apiHelper()
                ->createCharge(
                    ChargeType::fromNative($plan->getType()->toNative()),
                    $this->chargeHelper->details($plan, $shop, $host)
                );

            $confirmationUrl = $api['confirmation_url'];
        }

        return $confirmationUrl;
    }
}
