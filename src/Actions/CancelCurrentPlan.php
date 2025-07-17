<?php

namespace Osiset\ShopifyApp\Actions;

use Osiset\ShopifyApp\Contracts\Commands\Charge as IChargeCommand;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Objects\Values\ShopId;
use Osiset\ShopifyApp\Services\ChargeHelper;

class CancelCurrentPlan
{
    public function __construct(
        protected IShopQuery $shopQuery,
        protected IChargeCommand $chargeCommand,
        protected ChargeHelper $chargeHelper
    ) {
    }

    public function __invoke(ShopId $shopId): bool
    {
        $shop = $this->shopQuery->getById($shopId);
        $plan = $shop->plan;

        if (! $plan) {
            return false;
        }

        $lastPlanCharge = $this->chargeHelper->chargeForPlan($shop->plan->getId(), $shop);

        if ($lastPlanCharge && ! $lastPlanCharge->isDeclined() && ! $lastPlanCharge->isCancelled()) {
            $this->chargeCommand->cancel($lastPlanCharge->getReference());

            return true;
        }

        return false;
    }
}
