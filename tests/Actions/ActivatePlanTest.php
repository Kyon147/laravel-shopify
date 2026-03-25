<?php

namespace Osiset\ShopifyApp\Test\Actions;

use Illuminate\Support\Facades\Event;
use Osiset\ShopifyApp\Actions\ActivatePlan;
use Osiset\ShopifyApp\Messaging\Events\PlanActivatedEvent;
use Osiset\ShopifyApp\Objects\Values\ChargeId;
use Osiset\ShopifyApp\Objects\Values\ChargeReference;
use Osiset\ShopifyApp\Storage\Models\Charge;
use Osiset\ShopifyApp\Storage\Models\Plan;
use Osiset\ShopifyApp\Test\Stubs\Api as ApiStub;
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
        $this->setApiStub();
        ApiStub::stubResponses(['post_recurring_application_charges']);

        // Activate the charge
        $result = call_user_func(
            $this->action,
            $shop->getId(),
            $plan->getId(),
            ChargeReference::fromNative(12345),
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
        $this->setApiStub();
        ApiStub::stubResponses(['post_application_charges']);

        // Activate the charge
        $result = call_user_func(
            $this->action,
            $shop->getId(),
            $plan->getId(),
            ChargeReference::fromNative(12345),
            $hostValue
        );

        $this->assertInstanceOf(ChargeId::class, $result);
        Event::assertDispatched(PlanActivatedEvent::class);
    }

    //TODO we need to test for both myshopify and admin hosts
}
