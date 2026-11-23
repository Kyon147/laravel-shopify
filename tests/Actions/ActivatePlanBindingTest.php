<?php

namespace Osiset\ShopifyApp\Test\Actions;

use Illuminate\Support\Facades\Event;
use Osiset\ShopifyApp\Actions\ActivatePlan;
use Osiset\ShopifyApp\Actions\GetPlanUrl;
use Osiset\ShopifyApp\Exceptions\HttpException;
use Osiset\ShopifyApp\Messaging\Events\PlanActivatedEvent;
use Osiset\ShopifyApp\Objects\Values\ChargeReference;
use Osiset\ShopifyApp\Objects\Values\NullablePlanId;
use Osiset\ShopifyApp\Storage\Models\Charge;
use Osiset\ShopifyApp\Storage\Models\Plan;
use Osiset\ShopifyApp\Test\Stubs\BillingApi;
use Osiset\ShopifyApp\Test\TestCase;

class ActivatePlanBindingTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        BillingApi::install();
        Event::fake([PlanActivatedEvent::class]);
    }

    /**
     * @dataProvider editedCallbackProvider
     */
    public function testCannotSubstitutePlanInAuthenticatedCallback(string $frontend, int $premiumPrice, bool $sameName = false): void
    {
        config(['shopify-app.frontend_type' => $frontend]);
        [$shop, $basic, $host] = $this->createCharge();
        $premium = factory(Plan::class)->states('type_recurring')->create([
            'name' => $sameName ? $basic->name : 'Premium', 'price' => $premiumPrice,
        ]);
        BillingApi::approve($shop->name, 1001, 'accepted');
        BillingApi::$requests = [];

        $response = $this->getJson('/billing/process/'.$premium->id.'?'.http_build_query([
            'shop' => $shop->name,
            'charge_id' => 1001,
            'host' => $host,
        ]), ['Authorization' => 'Bearer '.$this->buildToken()]);

        $response->assertForbidden();
        $response->assertExactJson(['error' => 'Charge does not match the requested plan.']);
        $this->assertNull($shop->fresh()->plan_id);
        $this->assertDatabaseCount('charges', 0);
        $this->assertCount(1, BillingApi::$requests);
        $this->assertSame('GET', BillingApi::$requests[0][1]);
        $this->assertSame('accepted', BillingApi::$charges[$shop->name][1001]['status']);
        Event::assertNotDispatched(PlanActivatedEvent::class);
    }

    public static function editedCallbackProvider(): array
    {
        return [
            'MPA higher price' => ['MPA', 100],
            'SPA higher price' => ['SPA', 100],
            'same price different entitlement' => ['MPA', 10],
            'identical billing terms different plan' => ['MPA', 10, true],
        ];
    }

    /**
     * @dataProvider invalidChargeProvider
     */
    public function testRejectsUnboundOrUnapprovedChargeBeforeBillingChanges(array $overrides, string $error): void
    {
        [$shop, $plan, $host] = $this->createCharge();
        $oldPlan = factory(Plan::class)->states('type_recurring')->create();
        $shop->forceFill(['plan_id' => $oldPlan->id])->save();
        $oldCharge = factory(Charge::class)->states('type_recurring')->create([
            'charge_id' => 99,
            'plan_id' => $oldPlan->id,
            'user_id' => $shop->id,
            'status' => 'ACTIVE',
        ]);
        BillingApi::$charges[$shop->name][1001] = array_merge(
            BillingApi::$charges[$shop->name][1001],
            ['status' => 'accepted'],
            $overrides
        );
        BillingApi::$requests = [];

        try {
            ($this->app->make(ActivatePlan::class))($shop->getId(), $plan->getId(), ChargeReference::fromNative(1001), $host);
            $this->fail('An unbound or unapproved charge must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getCode());
            $this->assertSame($error, $exception->getMessage());
        }

        $this->assertSame($oldPlan->id, (int) $shop->fresh()->plan_id);
        $this->assertSame('ACTIVE', $oldCharge->fresh()->status);
        $this->assertNull($oldCharge->fresh()->cancelled_on);
        $this->assertDatabaseCount('charges', 1);
        $this->assertCount(1, BillingApi::$requests);
        $this->assertSame('GET', BillingApi::$requests[0][1]);
        Event::assertNotDispatched(PlanActivatedEvent::class);
    }

    public static function invalidChargeProvider(): array
    {
        $mismatch = 'Charge does not match the requested plan.';
        $unapproved = 'Charge has not been approved.';

        return [
            'missing association' => [['return_url' => null], $mismatch],
            'different association' => [['return_url' => 'https://localhost/billing/process/999?shop=other.myshopify.com'], $mismatch],
            'wrong reference' => [['id' => 1002], $mismatch],
            'wrong price' => [['price' => '1.00'], $mismatch],
            'fractional price cannot round up' => [['price' => '9.999999999'], $mismatch],
            'missing price' => [['price' => null], $mismatch],
            'malformed price' => [['price' => ['10']], $mismatch],
            'test charge for paid plan' => [['test' => true], $mismatch],
            'different name' => [['name' => 'Another plan'], $mismatch],
            'different trial' => [['trial_days' => 30], $mismatch],
            'different usage cap' => [['capped_amount' => '100'], $mismatch],
            'pending' => [['status' => 'pending'], $unapproved],
            'declined' => [['status' => 'declined'], $unapproved],
            'cancelled' => [['status' => 'cancelled'], $unapproved],
            'expired' => [['status' => 'expired'], $unapproved],
        ];
    }

    /**
     * @dataProvider approvedChargeProvider
     */
    public function testActivatesOnlyMatchingApprovedCharge(string $type, string $status, string $price): void
    {
        [$shop, $plan, $host] = $this->createCharge($type);
        BillingApi::$charges[$shop->name][1001]['price'] = $price;
        BillingApi::approve($shop->name, 1001, $status);
        BillingApi::$requests = [];

        $id = ($this->app->make(ActivatePlan::class))($shop->getId(), $plan->getId(), ChargeReference::fromNative(1001), $host);

        $this->assertSame($plan->id, (int) $shop->fresh()->plan_id);
        $this->assertDatabaseHas('charges', ['id' => $id->toNative(), 'plan_id' => $plan->id, 'price' => 10, 'status' => 'ACTIVE']);
        $this->assertCount($status === 'accepted' ? 2 : 1, BillingApi::$requests);
        Event::assertDispatched(PlanActivatedEvent::class, 1);

        // Refreshing the same completed callback must not create another charge or event.
        $again = ($this->app->make(ActivatePlan::class))($shop->getId(), $plan->getId(), ChargeReference::fromNative(1001), $host);
        $this->assertSame($id->toNative(), $again->toNative());
        $this->assertDatabaseCount('charges', 1);
        Event::assertDispatched(PlanActivatedEvent::class, 1);
    }

    public static function approvedChargeProvider(): array
    {
        return [
            'modern recurring' => ['recurring', 'active', '10.00'],
            'legacy recurring' => ['recurring', 'accepted', '0010.000'],
            'modern one-time' => ['onetime', 'active', '10'],
            'legacy one-time' => ['onetime', 'accepted', '10.0'],
            'annual subscription' => ['annual', 'active', '10.0000'],
            'trial subscription' => ['trial', 'active', '10.00'],
            'capped usage subscription' => ['usage', 'active', '10.00'],
        ];
    }

    /**
     * @dataProvider invalidActivationProvider
     */
    public function testFailedActivationDoesNotAssignPlan(array $overrides): void
    {
        [$shop, $plan, $host] = $this->createCharge();
        BillingApi::approve($shop->name, 1001, 'accepted');
        BillingApi::$activationOverrides = $overrides;

        $response = $this->getJson('/billing/process/'.$plan->id.'?'.http_build_query([
            'shop' => $shop->name, 'charge_id' => 1001, 'host' => $host,
        ]), ['Authorization' => 'Bearer '.$this->buildToken()]);

        $response->assertForbidden();
        $this->assertNull($shop->fresh()->plan_id);
        $this->assertDatabaseCount('charges', 0);
        Event::assertNotDispatched(PlanActivatedEvent::class);
    }

    public static function invalidActivationProvider(): array
    {
        return [
            'not active' => [['status' => 'declined']],
            'changed association' => [['return_url' => 'https://localhost/billing/process/999']],
            'changed amount' => [['price' => 1]],
        ];
    }

    /**
     * @dataProvider invalidReferenceProvider
     */
    public function testMalformedChargeReferenceDoesNotReachShopify(mixed $reference): void
    {
        $shop = factory($this->model)->create(['name' => 'shop-name.myshopify.com']);
        $response = $this->getJson('/billing/process/1?'.http_build_query([
            'shop' => $shop->name,
            'charge_id' => $reference,
            'host' => base64_encode($shop->name.'/admin'),
        ]), ['Authorization' => 'Bearer '.$this->buildToken()]);

        $response->assertStatus(400);
        $response->assertExactJson(['error' => 'Invalid charge ID.']);
        $this->assertSame([], BillingApi::$requests);
        $this->assertDatabaseCount('charges', 0);
    }

    public static function invalidReferenceProvider(): array
    {
        return [[0], [-1], ['1oops'], ['1.5'], ['1e3'], [['1001']]];
    }

    public function testOldCompletedChargeCannotReapplyEntitlements(): void
    {
        [$shop, $plan, $host] = $this->createCharge('onetime');
        BillingApi::approve($shop->name, 1001);
        $action = $this->app->make(ActivatePlan::class);
        $action($shop->getId(), $plan->getId(), ChargeReference::fromNative(1001), $host);
        $replacement = factory(Plan::class)->states('type_onetime')->create();
        $shop->forceFill(['plan_id' => $replacement->id])->save();

        try {
            $action($shop->getId(), $plan->getId(), ChargeReference::fromNative(1001), $host);
            $this->fail('An older charge must not restore an entitlement.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getCode());
        }

        $this->assertSame($replacement->id, (int) $shop->fresh()->plan_id);
        $this->assertDatabaseCount('charges', 1);
        Event::assertDispatched(PlanActivatedEvent::class, 1);
    }

    private function createCharge(string $type = 'recurring'): array
    {
        $shop = factory($this->model)->create(['name' => 'shop-name.myshopify.com', 'plan_id' => null]);
        $plan = factory(Plan::class)->states('type_'.($type === 'onetime' ? 'onetime' : 'recurring'))
            ->create(['name' => 'Basic', 'price' => 10, 'trial_days' => 0, 'test' => false]);
        if ($type === 'annual') {
            $plan->forceFill(['interval' => 'ANNUAL'])->save();
        }
        if ($type === 'trial') {
            $plan->forceFill(['trial_days' => 3])->save();
        }
        if ($type === 'usage') {
            $plan->forceFill(['capped_amount' => 100, 'terms' => 'One email'])->save();
        }
        $host = base64_encode($shop->name.'/admin');
        ($this->app->make(GetPlanUrl::class))($shop->getId(), NullablePlanId::fromNative($plan->id), $host);

        return [$shop, $plan, $host];
    }
}
