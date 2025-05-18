<?php

namespace Osiset\ShopifyApp\Actions;

use Illuminate\Support\Carbon;
use Osiset\ShopifyApp\Contracts\Commands\Charge as IChargeCommand;
use Osiset\ShopifyApp\Exceptions\ChargeNotRecurringOrOnetimeException;
use Osiset\ShopifyApp\Objects\Enums\ChargeType;
use Osiset\ShopifyApp\Objects\Values\ChargeReference;
use Osiset\ShopifyApp\Services\ChargeHelper;

class CancelCharge
{
    public function __construct(
        protected IChargeCommand $chargeCommand,
        protected ChargeHelper $chargeHelper
    ) {
    }

    /**
     * @throws Exception
     */
    public function __invoke(ChargeReference $chargeRef): bool
    {
        $helper = $this->chargeHelper->useCharge($chargeRef);
        $charge = $helper->getCharge();

        if (! $charge->isType(ChargeType::CHARGE()) && ! $charge->isType(ChargeType::RECURRING())) {
            // Not a recurring or one-time charge, someone trying to cancel a usage charge?
            throw new ChargeNotRecurringOrOnetimeException(
                'Cancel may only be called for single and recurring charges.'
            );
        }

        return $this->chargeCommand->cancel(
            $chargeRef,
            Carbon::today(),
            Carbon::today()->addDays($helper->remainingDaysForPeriod())
        );
    }
}
