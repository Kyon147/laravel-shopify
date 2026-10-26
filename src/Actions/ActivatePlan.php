<?php

namespace Osiset\ShopifyApp\Actions;

use Gnikyt\BasicShopifyAPI\ResponseAccess;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Osiset\ShopifyApp\Contracts\Commands\Charge as IChargeCommand;
use Osiset\ShopifyApp\Contracts\Commands\Shop as IShopCommand;
use Osiset\ShopifyApp\Contracts\Objects\Values\PlanId;
use Osiset\ShopifyApp\Contracts\Queries\Plan as IPlanQuery;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Exceptions\HttpException;
use Osiset\ShopifyApp\Messaging\Events\PlanActivatedEvent;
use Osiset\ShopifyApp\Objects\Enums\ChargeStatus;
use Osiset\ShopifyApp\Objects\Enums\ChargeType;
use Osiset\ShopifyApp\Objects\Enums\PlanType;
use Osiset\ShopifyApp\Objects\Transfers\Charge as ChargeTransfer;
use Osiset\ShopifyApp\Objects\Transfers\PlanDetails;
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
        if (!$shop || !$plan) {
            throw new HttpException('Shop or plan is unavailable.', Response::HTTP_FORBIDDEN);
        }

        $chargeType = ChargeType::fromNative($plan->getType()->toNative());
        $existing = $shop->charges()->withTrashed()
            ->where('charge_id', $chargeRef->toNative())
            ->where('type', $chargeType->toNative())
            ->latest('id')->first();
        $details = $this->chargeHelper->details($plan, $shop, $host);
        if ($existing && (int) $existing->plan_id === $planId->toNative()) {
            // Trial calculation changes after activation; retries use the recorded approval terms.
            $details->trialDays = (int) $existing->trial_days;
        }

        // Shopify's stored return URL binds this charge to the original store and plan.
        // Read it with this store's credentials before activation or local billing changes.
        $response = $shop->apiHelper()->getCharge($chargeType, $chargeRef);
        $this->verifyCharge($response, $chargeRef, $details);
        if (!in_array($response['status'] ?? null, ['accepted', 'active'], true)) {
            throw new HttpException('Charge has not been approved.', Response::HTTP_FORBIDDEN);
        }

        // A completed callback may be retried, but cannot reapply an older entitlement.
        if ($existing) {
            if (!$existing->trashed() && $existing->isActive()
                && (int) $existing->plan_id === $planId->toNative()
                && (int) $shop->plan_id === $planId->toNative()
            ) {
                return $existing->getId();
            }

            throw new HttpException('Charge has already been applied.', Response::HTTP_FORBIDDEN);
        }

        // Modern Shopify charges are already active after approval. Support legacy acceptance too.
        if ($response['status'] === 'accepted') {
            $response = $shop->apiHelper()->activateCharge($chargeType, $chargeRef);
            $this->verifyCharge($response, $chargeRef, $details);
            if (($response['status'] ?? null) !== 'active') {
                throw new HttpException('Charge has not been activated.', Response::HTTP_FORBIDDEN);
            }
        }
        // Cancel the shop's current plan
        call_user_func($this->cancelCurrentPlan, $shopId);

        $transfer = new ChargeTransfer();
        $transfer->shopId = $shopId;
        $transfer->planId = $planId;
        $transfer->chargeReference = $chargeRef;
        $transfer->chargeType = $chargeType;
        $transfer->chargeStatus = ChargeStatus::fromNative(strtoupper($response['status']));
        $transfer->planDetails = $details;

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

    /**
     * Verify the association and billing terms returned by Shopify, never callback input.
     */
    protected function verifyCharge(ResponseAccess $charge, ChargeReference $reference, PlanDetails $details): void
    {
        if ((string) ($charge['id'] ?? '') !== (string) $reference->toNative()
            || ($charge['return_url'] ?? null) !== $details->returnUrl
            || ($charge['name'] ?? null) !== $details->name
            || $this->decimalAmount($charge['price'] ?? null) === null
            || $this->decimalAmount($charge['price']) !== $this->decimalAmount($details->price)
            || (bool) ($charge['test'] ?? false) !== $details->test
            || (string) ($charge['trial_days'] ?? 0) !== (string) $details->trialDays
            || $this->decimalAmount($charge['capped_amount'] ?? 0) !== $this->decimalAmount($details->cappedAmount ?? 0)
            || ($charge['terms'] ?? null) !== $details->terms
        ) {
            throw new HttpException('Charge does not match the requested plan.', Response::HTTP_FORBIDDEN);
        }
    }

    /**
     * Compare decimal amounts without rounding or floating-point arithmetic.
     */
    protected function decimalAmount(mixed $value): ?string
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value))
            || !preg_match('/^([0-9]+)(?:\.([0-9]+))?$/D', (string) $value, $parts)
        ) {
            return null;
        }

        return (ltrim($parts[1], '0') ?: '0').'.'.rtrim($parts[2] ?? '', '0');
    }
}
