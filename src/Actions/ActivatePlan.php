<?php

namespace Osiset\ShopifyApp\Actions;

use Illuminate\Support\Carbon;
use Osiset\ShopifyApp\Contracts\Commands\Charge as IChargeCommand;
use Osiset\ShopifyApp\Contracts\Commands\Shop as IShopCommand;
use Osiset\ShopifyApp\Contracts\Objects\Values\PlanId;
use Osiset\ShopifyApp\Contracts\Queries\Plan as IPlanQuery;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Messaging\Events\PlanActivatedEvent;
use Osiset\ShopifyApp\Objects\Enums\ChargeStatus;
use Osiset\ShopifyApp\Objects\Enums\ChargeType;
use Osiset\ShopifyApp\Objects\Enums\PlanType;
use Osiset\ShopifyApp\Objects\Transfers\Charge as ChargeTransfer;
use Osiset\ShopifyApp\Objects\Values\ChargeId;
use Osiset\ShopifyApp\Objects\Values\ChargeReference;
use Osiset\ShopifyApp\Objects\Values\ShopId;
use Osiset\ShopifyApp\Services\ChargeHelper;

class ActivatePlan
{
    /**
     * @param callable $cancelCurrentPlan
     */
    public function __construct(
        protected $cancelCurrentPlan,
        protected ChargeHelper $chargeHelper,
        protected IShopQuery $shopQuery,
        protected IPlanQuery $planQuery,
        protected IChargeCommand $chargeCommand,
        protected IShopCommand $shopCommand
    ) {
    }

    /**
     * TODO: Rethrow an API exception.
     */
    public function __invoke(ShopId $shopId, PlanId $planId, ChargeReference $chargeRef, string $host): ChargeId
    {
        $shop = $this->shopQuery->getById($shopId);
        $plan = $this->planQuery->getById($planId);
        $chargeType = ChargeType::fromNative($plan->getType()->toNative());

        // Activate the plan on Shopify
        $response = $shop->apiHelper()->activateCharge($chargeType, $chargeRef);
        // Cancel the shop's current plan
        call_user_func($this->cancelCurrentPlan, $shopId);
        // Cancel the existing charge if it exists (happens if someone refreshes during)
        $this->chargeCommand->delete($chargeRef, $shopId);

        $transfer = new ChargeTransfer();
        $transfer->shopId = $shopId;
        $transfer->planId = $planId;
        $transfer->chargeReference = $chargeRef;
        $transfer->chargeType = $chargeType;
        $transfer->chargeStatus = ChargeStatus::fromNative(strtoupper($response['status']));
        $transfer->planDetails = $this->chargeHelper->details($plan, $shop, $host);

        if ($plan->isType(PlanType::RECURRING())) {
            $transfer->activatedOn = new Carbon($response['activated_on']);
            $transfer->billingOn = new Carbon($response['billing_on']);
            $transfer->trialEndsOn = new Carbon($response['trial_ends_on']);
        } else {
            $transfer->activatedOn = Carbon::today();
            $transfer->billingOn = null;
            $transfer->trialEndsOn = null;
        }

        $charge = $this->chargeCommand->make($transfer);
        $this->shopCommand->setToPlan($shopId, $planId);

        event(new PlanActivatedEvent($shop, $plan, $charge));

        return $charge;
    }
}
