<?php

namespace Osiset\ShopifyApp\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Osiset\ShopifyApp\Contracts\Queries\Charge as IChargeQuery;
use Osiset\ShopifyApp\Contracts\ShopModel as IShopModel;
use Osiset\ShopifyApp\Objects\Enums\ChargeType;
use Osiset\ShopifyApp\Objects\Transfers\PlanDetails as PlanDetailsTransfer;
use Osiset\ShopifyApp\Objects\Values\ChargeReference;
use Osiset\ShopifyApp\Objects\Values\PlanId;
use Osiset\ShopifyApp\Storage\Models\Charge as ChargeModel;
use Osiset\ShopifyApp\Storage\Models\Plan;
use Osiset\ShopifyApp\Util;

/**
 * Basic helper class for charges which encapsulates
 * logic for the charge model for things such as trial
 * determination, charge retrieval, etc.
 */
class ChargeHelper
{
    /**
     * The querier for charges.
     *
     * @var IChargeQuery
     */
    protected $chargeQuery;

    /**
     * The charge record.
     *
     * @var ChargeModel
     */
    protected $charge;

    /**
     * Contructor.
     *
     * @param IChargeQuery $chargeQuery The querier for charges.
     *
     * @return void
     */
    public function __construct(IChargeQuery $chargeQuery)
    {
        $this->chargeQuery = $chargeQuery;
    }

    /**
     * Gets the charge's data from Shopify.
     *
     * @param IShopModel $shop The shop.
     *
     * @return array
     */
    public function retrieve(IShopModel $shop)
    {
        return $shop->apiHelper()->getCharge(
            $this->charge->getType(),
            $this->charge->getReference()
        );
    }

    /**
     * Get the charge in context.
     *
     * @return ChargeModel
     */
    public function getCharge(): ChargeModel
    {
        return $this->charge;
    }

    /**
     * Return the end date of the current period.
     *
     * @return string
     */
    public function periodEndDate(): string
    {
        return Carbon::parse($this->periodBeginDate())->addDays(30)->toDateString();
    }

    /**
     * Return the date when the current period has begun.
     *
     * @return string
     */
    public function periodBeginDate(): string
    {
        $pastPeriods = (int) (Carbon::parse($this->charge->activated_on)->diffInDays(Carbon::today()) / 30);
        $periodBeginDate = Carbon::parse($this->charge->activated_on)->addDays(30 * $pastPeriods)->toDateString();

        return $periodBeginDate;
    }

    /**
     * Returns the remaining days for the current recurring charge.
     *
     * @return int
     */
    public function remainingDaysForPeriod(): int
    {
        $pastDaysForPeriod = $this->pastDaysForPeriod();
        if ($pastDaysForPeriod === null ||
            ($pastDaysForPeriod === 0 && Carbon::parse($this->charge->cancelled_on)->lt(Carbon::today()))
        ) {
            return 0;
        }

        return 30 - $pastDaysForPeriod;
    }

    /**
     * Returns the past days for the current recurring charge.
     *
     * @return int|null
     */
    public function pastDaysForPeriod(): ?int
    {
        if ($this->charge->cancelled_on &&
            abs(Carbon::now()->diffInDays(Carbon::parse($this->charge->cancelled_on))) > 30
        ) {
            return null;
        }

        $pastDaysInPeriod = Carbon::parse($this->periodBeginDate())->diffInDays(Carbon::today());

        return $pastDaysInPeriod;
    }

    /**
     * Checks if plan was cancelled and is expired.
     *
     * @return bool
     */
    public function hasExpired(): bool
    {
        if ($this->charge->isCancelled()) {
            return Carbon::parse($this->charge->expires_on)->lte(Carbon::today());
        }

        return false;
    }

    /**
     * Returns the used trial days.
     *
     * @return int|null
     */
    public function usedTrialDays(): ?int
    {
        if (!$this->charge->isTrial()) {
            return null;
        }

        return $this->charge->trial_days - $this->remainingTrialDays();
    }

    /**
     * Returns the remaining trial days.
     *
     * @return ?int
     */
    public function remainingTrialDays(): ?int
    {
        if (!$this->charge->isTrial()) {
            return null;
        }

        return $this->isActiveTrial() ?
            Carbon::today()->diffInDays($this->charge->trial_ends_on) :
            0;
    }

    /**
     * Checks if the charge is currently in trial.
     *
     * @return bool
     */
    public function isActiveTrial(): bool
    {
        return $this->charge->isTrial() &&
            Carbon::today()->lte(Carbon::parse($this->charge->trial_ends_on));
    }

    /**
     * Returns the charge params used with the create request.
     *
     * @param Plan $plan The plan.
     * @param IShopModel $shop The shop the plan is for.
     *
     * @return PlanDetailsTransfer
     */
    public function details(Plan $plan, IShopModel $shop, string $host): PlanDetailsTransfer
    {
        // Handle capped amounts for UsageCharge API
        $isCapped = isset($plan->capped_amount) && $plan->capped_amount > 0;

        // Build the details object
        $transfer = new PlanDetailsTransfer();
        $transfer->name = $plan->name;
        $transfer->price = $plan->price;
        $transfer->interval = $plan->getInterval()->toNative();
        $transfer->test = $plan->isTest();
        $transfer->trialDays = $this->determineTrialDaysRemaining($plan, $shop);
        $transfer->cappedAmount = $isCapped ? $plan->capped_amount : null;
        $transfer->terms = $isCapped ? $plan->terms : null;
        $transfer->returnUrl = URL::secure(
            Util::getShopifyConfig('billing_redirect'),
            ['plan' => $plan->getId()->toNative()]
        ).'?'.http_build_query([
            'shop' => $shop->getDomain()->toNative(),
            'host' => $host,
        ]);

        return $transfer;
    }

    /**
     * Determines the trial days for the plan.
     * Detects if reinstall is happening and properly adjusts.
     *
     * @param Plan $plan The plan.
     * @param IShopModel $shop The shop the plan is for.
     *
     * @return int
     */
    protected function determineTrialDaysRemaining(Plan $plan, IShopModel $shop): ?int
    {
        if (!$plan->hasTrial()) {
            // Not a trial-type plan, return none
            return 0;
        }

        // See if the shop has been charged for this plan before..
        // If they have, its a good chance its a reinstall
        $pc = $this->chargeForPlan($plan->getId(), $shop);
        if ($pc !== null) {
            $this->useCharge($pc->getReference());
            $result = $this->remainingTrialDaysFromCancel();
        } else {
            // Seems like a fresh trial... return the days set in database
            $result = $plan->trial_days;
        }

        return $result;
    }

    /**
     * Gets the last single or recurring charge for the shop.
     *
     * @param PlanId $planId The plan ID to check with.
     * @param IShopModel $shop The shop the plan is for.
     *
     * @return ChargeModel
     */
    public function chargeForPlan(PlanId $planId, IShopModel $shop): ?ChargeModel
    {
        return $shop
            ->charges()
            ->withTrashed()
            ->whereIn('type', [ChargeType::RECURRING()->toNative(), ChargeType::CHARGE()->toNative()])
            ->where('plan_id', $planId->toNative())
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Set the charge in context.
     *
     * @param ChargeReference $chargeRef The charge ID.
     *
     * @return self
     */
    public function useCharge(ChargeReference $chargeRef): self
    {
        // Get the charge
        $this->charge = $this->chargeQuery->getByReference($chargeRef);

        return $this;
    }

    /**
     * Returns the remaining trial days from cancellation date.
     *
     * @return int|null
     */
    public function remainingTrialDaysFromCancel(): ?int
    {
        if (!$this->charge->isTrial()) {
            return null;
        }

        $cancelledDate = Carbon::parse($this->charge->cancelled_on);
        $trialEndsDate = Carbon::parse($this->charge->trial_ends_on);

        // Ensure cancelled date happened before the trial was supposed to end
        if ($this->charge->isCancelled() && $cancelledDate->lte($trialEndsDate)) {
            // Diffeence the two dates and subtract from the total trial days to get whats remaining
            return $this->charge->trial_days - ($this->charge->trial_days - $cancelledDate->diffInDays($trialEndsDate));
        }

        return 0;
    }
}
