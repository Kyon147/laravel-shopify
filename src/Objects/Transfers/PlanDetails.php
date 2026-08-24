<?php

namespace Osiset\ShopifyApp\Objects\Transfers;

/**
 * Represents details for a plan.
 */
final class PlanDetails extends AbstractTransfer
{
    /**
     * Plan name.
     *
     * @var string
     */
    public $name;

    /**
     * Plan price.
     *
     * @var float
     */
    public $price;

    /**
     * Plan interval.
     *
     * @var string
     */
    public $interval;

    /**
     * Plan test or real?
     *
     * @var bool
     */
    public $test;
    /**
     * Plan trial days.
     *
     * @var int
     */
    public $trialDays;

    /**
     * Capped amount value (for usage charge).
     *
     * @var float|null
     */
    public $cappedAmount;

    /**
     * Capped terms (for usage charge).
     *
     * @var string|null
     */
    public $terms;

    /**
     * Plan return URL.
     *
     * @var string|null
     */
    public $returnUrl;

    /**
     * The total number of billing intervals to which the discount will be applied.
     * Must be greater than 0.
     * The discount will be applied to an indefinite number of billing intervals if this value is left blank.
     *
     * @var int|null
     */
    public $discountDuration;

    /**
     * The monetary value of a discount.
     *
     * @var float|null
     */
    public $discountAmount;

    /**
     * The percentage value of a discount.
     *
     * @var float|null
     */
    public $discountPercentage;

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'price' => $this->price,
            'interval' => $this->interval,
            'test' => $this->test,
            'trial_days' => $this->trialDays,
            'return_url' => $this->returnUrl,
            'terms' => $this->terms,
            'capped_amount' => $this->cappedAmount,
            'discount_duration' => $this->discountDuration,
            'discount_amount' => $this->discountAmount,
            'discount_percentage' => $this->discountPercentage,
        ];
    }
}
