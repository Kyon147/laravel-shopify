<?php

namespace Osiset\ShopifyApp\Actions;

use Osiset\ShopifyApp\Contracts\Commands\Charge as IChargeCommand;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Exceptions\ChargeNotRecurringException;
use Osiset\ShopifyApp\Objects\Enums\ChargeType;
use Osiset\ShopifyApp\Objects\Transfers\UsageCharge as UsageChargeTransfer;
use Osiset\ShopifyApp\Objects\Transfers\UsageChargeDetails as UsageChargeDetailsTransfer;
use Osiset\ShopifyApp\Objects\Values\ChargeId;
use Osiset\ShopifyApp\Objects\Values\ChargeReference;
use Osiset\ShopifyApp\Objects\Values\ShopId;
use Osiset\ShopifyApp\Services\ChargeHelper;

class ActivateUsageCharge
{
    public function __construct(
        protected ChargeHelper $chargeHelper,
        protected IChargeCommand $chargeCommand,
        protected IShopQuery $shopQuery
    ) {
    }

    /**
     * TODO: Rethrow an API exception.
     *
     * @throws ChargeNotRecurringException
     */
    public function __invoke(ShopId $shopId, UsageChargeDetailsTransfer $ucd): ChargeId|bool
    {
        $shop = $this->shopQuery->getById($shopId);
        // Ensure we have a recurring charge
        $currentCharge = $this->chargeHelper->chargeForPlan($shop->plan->getId(), $shop);

        if (! $currentCharge->isType(ChargeType::RECURRING())) {
            throw new ChargeNotRecurringException('Can only create usage charges for recurring charge.');
        }

        // Create the usage charge
        $ucd->chargeReference = $currentCharge->getReference();
        $response = $shop->apiHelper()->createUsageCharge($ucd);

        if (! $response) {
            // Could not make usage charge, limit possibly reached
            return false;
        }

        $transfer = new UsageChargeTransfer();
        $transfer->shopId = $shopId;
        $transfer->planId = $shop->plan->getId();
        $transfer->chargeReference = ChargeReference::fromNative((int) $response['id']);
        $transfer->details = $ucd;

        return $this->chargeCommand->makeUsage($transfer);
    }
}
