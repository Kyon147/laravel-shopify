<?php

namespace Osiset\ShopifyApp\Test\Actions;

use Illuminate\Support\Facades\Event;
use Osiset\ShopifyApp\Actions\ActivatePlan;
use Osiset\ShopifyApp\Actions\GetPlanUrl;
use Osiset\ShopifyApp\Messaging\Events\PlanActivatedEvent;
use Osiset\ShopifyApp\Objects\Values\ChargeId;
use Osiset\ShopifyApp\Objects\Values\ChargeReference;
use Osiset\ShopifyApp\Objects\Values\NullablePlanId;
use Osiset\ShopifyApp\Storage\Models\Charge;
use Osiset\ShopifyApp\Storage\Models\Plan;
use Osiset\ShopifyApp\Test\Stubs\BillingApi;
use Osiset\ShopifyApp\Test\TestCase;
use Osiset\ShopifyApp\Util;

class ActivatePlanTest extends TestCase
{
    /**
     * @var ActivatePlan
     */
    protected $action;

    public function setUp(): void
    {
        parent::setUp();

        $this->action = $this->app->make(ActivatePlan::class);
    }

    public function testRunRecurring(): void
    {
        Event::fake();
        // Create a plan
        $plan = factory(Util::getShopifyConfig('models.plan', Plan::class))->states('type_recurring')->create();

        // Create the shop with the plan attached
        $shop = factory($this->model)->create([
            'plan_id' => $plan->getId()->toNative(),
        ]);

        // Create a charge for the plan and shop
        factory(Util::getShopifyConfig('models.charge', Charge::class))->states('type_recurring')->create([
            'charge_id' => 12345,
            'plan_id' => $plan->getId()->toNative(),
            'user_id' => $shop->getId()->toNative(),
        ]);
        $hostValue = urlencode(base64_encode($shop->getDomain()->toNative().'/admin'));
        // Setup API stub
        BillingApi::install();
        ($this->app->make(GetPlanUrl::class))($shop->getId(), NullablePlanId::fromNative($plan->id), $hostValue);
        BillingApi::approve($shop->name, 1001);

        // Activate the charge
        $result = call_user_func(
            $this->action,
            $shop->getId(),
            $plan->getId(),
            ChargeReference::fromNative(1001),
            $hostValue
        );

        $this->assertInstanceOf(ChargeId::class, $result);
        Event::assertDispatched(PlanActivatedEvent::class);
    }

    public function testRunOnetime(): void
    {
        Event::fake();
        // Create a plan
        $plan = factory(Util::getShopifyConfig('models.plan', Plan::class))->states('type_onetime')->create();

        // Create the shop with the plan attached
        $shop = factory($this->model)->create([
            'plan_id' => $plan->getId()->toNative(),
        ]);

        // Create a charge for the plan and shop
        factory(Util::getShopifyConfig('models.charge', Charge::class))->states('type_recurring')->create([
            'charge_id' => 12345,
            'plan_id' => $plan->getId()->toNative(),
            'user_id' => $shop->getId()->toNative(),
        ]);
        $hostValue = urlencode(base64_encode($shop->getDomain()->toNative().'/admin'));
        // Setup API stub
        BillingApi::install();
        ($this->app->make(GetPlanUrl::class))($shop->getId(), NullablePlanId::fromNative($plan->id), $hostValue);
        BillingApi::approve($shop->name, 1001);

        // Activate the charge
        $result = call_user_func(
            $this->action,
            $shop->getId(),
            $plan->getId(),
            ChargeReference::fromNative(1001),
            $hostValue
        );

        $this->assertInstanceOf(ChargeId::class, $result);
        Event::assertDispatched(PlanActivatedEvent::class);
    }

    //TODO we need to test for both myshopify and admin hosts
}
