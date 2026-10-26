<?php

namespace Osiset\ShopifyApp\Test\Actions;

use Illuminate\Support\Facades\Event;
use Osiset\ShopifyApp\Actions\ActivatePlan;
use Osiset\ShopifyApp\Exceptions\HttpException;
use Osiset\ShopifyApp\Messaging\Events\PlanActivatedEvent;
use Osiset\ShopifyApp\Objects\Values\ChargeReference;
use Osiset\ShopifyApp\Storage\Models\Charge;
use Osiset\ShopifyApp\Storage\Models\Plan;
use Osiset\ShopifyApp\Test\Stubs\BillingApi;
use Osiset\ShopifyApp\Test\TestCase;

/**
 * Charges here are written the way Shopify reads them back, not built from ChargeHelper.
 */
class ActivatePlanReadBackTest extends TestCase
{
    private const SHOP = 'shop-name.myshopify.com';
    private const HOST = 'YWRtaW4uc2hvcGlmeS5jb20vc3RvcmUvc2hvcC1uYW1l';

    private $shop;

    public function setUp(): void
    {
        parent::setUp();
        BillingApi::install();
        Event::fake([PlanActivatedEvent::class]);
    }

    public function testMonthlyChargeWithoutTermsReadBackVerifies(): void
    {
        $plan = $this->createPlan([
            'name' => 'Growth',
            'price' => 49,
            'capped_amount' => 49,
            'terms' => 'Up to 1000 orders a month',
        ]);
        $this->shopifyCharge($plan, [
            'name' => 'Growth',
            'price' => '49.00',
            'capped_amount' => '49.00',
            'trial_days' => 0,
            'test' => false,
            'terms' => null,
        ]);

        $this->activate($plan);

        $this->assertSame($plan->id, (int) $this->shop->fresh()->plan_id);
    }

    public function testAnnualChargeWithoutCapReadBackVerifies(): void
    {
        $plan = $this->createPlan([
            'name' => 'Yearly',
            'price' => 490,
            'interval' => 'ANNUAL',
            'capped_amount' => 100,
            'terms' => 'Usage terms',
        ]);
        // appSubscriptionCreate is sent no cap or terms for annual plans
        $this->shopifyCharge($plan, [
            'name' => 'Yearly',
            'price' => '490.00',
            'trial_days' => 0,
            'test' => false,
        ]);

        $this->activate($plan);

        $this->assertSame($plan->id, (int) $this->shop->fresh()->plan_id);
    }

    public function testZeroTrialDaysReadBackVerifiesAgainstNullTrial(): void
    {
        $plan = $this->createPlan(['name' => 'Trial', 'price' => 10, 'trial_days' => 7]);
        // An earlier charge for the plan without a trial end date leaves no trial to work out
        factory(Charge::class)->states('type_recurring')->create([
            'charge_id' => 900,
            'plan_id' => $plan->id,
            'user_id' => $this->shop->id,
            'status' => 'CANCELLED',
            'trial_ends_on' => null,
        ]);
        $this->shopifyCharge($plan, [
            'name' => 'Trial',
            'price' => '10.00',
            'trial_days' => 0,
            'test' => false,
        ]);

        $this->activate($plan);

        $this->assertSame($plan->id, (int) $this->shop->fresh()->plan_id);
    }

    public function testPriceMismatchStillThrows(): void
    {
        $plan = $this->createPlan(['name' => 'Growth', 'price' => 49]);
        $this->shopifyCharge($plan, [
            'name' => 'Growth',
            'price' => '4.90',
            'trial_days' => 0,
            'test' => false,
        ]);

        $this->assertMismatch($plan);
    }

    public function testReturnUrlMismatchStillThrows(): void
    {
        $plan = $this->createPlan(['name' => 'Growth', 'price' => 49]);
        $this->shopifyCharge($plan, [
            'name' => 'Growth',
            'price' => '49.00',
            'trial_days' => 0,
            'test' => false,
            'return_url' => 'https://localhost/billing/process/'.$plan->id.'?shop=other.myshopify.com&host='.self::HOST,
        ]);

        $this->assertMismatch($plan);
    }

    private function createPlan(array $attributes): Plan
    {
        $this->shop = factory($this->model)->create(['name' => self::SHOP, 'plan_id' => null]);

        return factory(Plan::class)->states('type_recurring')->create(array_merge(['trial_days' => 0, 'test' => false], $attributes));
    }

    private function shopifyCharge(Plan $plan, array $charge): void
    {
        BillingApi::$charges[self::SHOP][1001] = array_merge([
            'id' => 1001,
            'charge_type' => 'recurring_application_charge',
            'status' => 'active',
            'return_url' => 'https://localhost/billing/process/'.$plan->id.'?shop='.self::SHOP.'&host='.self::HOST,
            'activated_on' => '2026-10-21',
            'billing_on' => '2026-11-20',
            'trial_ends_on' => null,
        ], $charge);
    }

    private function activate(Plan $plan): void
    {
        ($this->app->make(ActivatePlan::class))($this->shop->getId(), $plan->getId(), ChargeReference::fromNative(1001), self::HOST);
    }

    private function assertMismatch(Plan $plan): void
    {
        try {
            $this->activate($plan);
            $this->fail('A charge that does not match the plan must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getCode());
            $this->assertSame('Charge does not match the requested plan.', $exception->getMessage());
        }

        $this->assertNull($this->shop->fresh()->plan_id);
        $this->assertDatabaseCount('charges', 0);
    }
}
