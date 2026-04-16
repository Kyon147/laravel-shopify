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
use Osiset\ShopifyApp\Objects\Enums\ChargeInterval;
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

        // GraphQL subscriptions (appSubscriptionCreate) are auto-activated on merchant approval —
        // no REST activate call needed. Derive all fields locally to avoid any API call
        // that would fail for shops with non-expiring (legacy) access tokens.
        $planDetails = $this->chargeHelper->details($plan, $shop, $host);

        // Cancel the shop's current plan
        call_user_func($this->cancelCurrentPlan, $shopId);
        // Cancel the existing charge if it exists (happens if someone refreshes during)
        $this->chargeCommand->delete($chargeRef, $shopId);

        $transfer = new ChargeTransfer();
        $transfer->shopId = $shopId;
        $transfer->planId = $planId;
        $transfer->chargeReference = $chargeRef;
        $transfer->chargeType = $chargeType;
        $transfer->chargeStatus = ChargeStatus::ACTIVE();
        $transfer->planDetails = $planDetails;

        if ($plan->isType(PlanType::RECURRING())) {
            $now = Carbon::now();
            $transfer->activatedOn = $now;
            $trialDays = (int) ($planDetails->trialDays ?? 0);
            $transfer->trialEndsOn = $trialDays > 0 ? $now->copy()->addDays($trialDays) : null;
            $transfer->billingOn = $plan->getInterval()->isSame(ChargeInterval::ANNUAL())
                ? $now->copy()->addYear()
                : $now->copy()->addDays(30);
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
