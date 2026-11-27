<?php

namespace Osiset\ShopifyApp\Test\Storage\Models;

use Osiset\ShopifyApp\Objects\Enums\PlanInterval;
use Osiset\ShopifyApp\Objects\Enums\PlanType;
use Osiset\ShopifyApp\Objects\Values\PlanId;
use Osiset\ShopifyApp\Storage\Models\Plan;
use Osiset\ShopifyApp\Test\TestCase;
use Osiset\ShopifyApp\Util;

class PlanTest extends TestCase
{
    public function testModel(): void
    {
        // Create a plan
        $plan = factory(Util::getShopifyConfig('models.plan', Plan::class))->states('type_recurring')->create();

        $this->assertInstanceOf(PlanId::class, $plan->getId());
        $this->assertCount(0, $plan->charges);
        $this->assertEquals(PlanType::Recurring, $plan->getType());
        $this->assertTrue($plan->isType(PlanType::Recurring));
        $this->assertSame('recurring_application_charge', $plan->getTypeApiString());
        $this->assertSame('recurring_application_charges', $plan->getTypeApiString(true));
        $this->assertFalse($plan->hasTrial());
        $this->assertFalse($plan->isOnInstall());
        $this->assertFalse($plan->isTest());
    }

    /**
     * A plan stored without an interval, or with an empty one, is a 30-day plan.
     */
    public function testMissingIntervalIsEvery30Days(): void
    {
        foreach ([null, ''] as $interval) {
            $plan = factory(Util::getShopifyConfig('models.plan', Plan::class))->states('type_recurring')->create([
                'interval' => $interval,
            ]);

            $this->assertSame(PlanInterval::Every30Days, $plan->fresh()->getInterval());
        }
    }
}
