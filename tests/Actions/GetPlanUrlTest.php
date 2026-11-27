<?php

namespace Osiset\ShopifyApp\Test\Actions;

use Osiset\ShopifyApp\Actions\GetPlanUrl;
use Osiset\ShopifyApp\Exceptions\ApiException;
use Osiset\ShopifyApp\Objects\Values\NullablePlanId;
use Osiset\ShopifyApp\Storage\Models\Plan;
use Osiset\ShopifyApp\Test\Stubs\Api as ApiStub;
use Osiset\ShopifyApp\Test\TestCase;
use Osiset\ShopifyApp\Util;

class GetPlanUrlTest extends TestCase
{
    /**
     * @var \Osiset\ShopifyApp\Actions\GetPlanUrl
     */
    protected $action;

    public function setUp(): void
    {
        parent::setUp();

        $this->action = $this->app->make(GetPlanUrl::class);
    }

    public function testRun30Days(): void
    {
        // Create a plan
        factory(Util::getShopifyConfig('models.plan', Plan::class))->states(['installable', 'type_recurring'])->create();

        // Create the shop with no plan
        $shop = factory($this->model)->create();
        $hostValue = base64_encode($shop->getDomain()->toNative().'/admin');
        // Setup API stub
        $this->setApiStub();
        ApiStub::stubResponses(['graphql_app_subscription_create']);

        $result = call_user_func(
            $this->action,
            $shop->getId(),
            NullablePlanId::fromNative(null),
            $hostValue
        );

        $this->assertStringContainsString('/admin/charges/4019552312/confirm_recurring_application_charge', $result);
    }

    public function testRunOneTime(): void
    {
        // Create a plan
        factory(Util::getShopifyConfig('models.plan', Plan::class))->states(['installable', 'type_onetime'])->create();

        // Create the shop with no plan
        $shop = factory($this->model)->create();
        $hostValue = base64_encode($shop->getDomain()->toNative().'/admin');
        // Setup API stub
        $this->setApiStub();
        ApiStub::stubResponses(['graphql_app_purchase_one_time_create']);

        $result = call_user_func(
            $this->action,
            $shop->getId(),
            NullablePlanId::fromNative(null),
            $hostValue
        );

        $this->assertStringContainsString('/admin/charges/1017262351/confirm_application_charge', $result);
    }

    public function testRunAnnual(): void
    {
        // Create a plan
        factory(Util::getShopifyConfig('models.plan', Plan::class))->states(['installable', 'type_recurring', 'interval_annual', 'usage'])->create();

        // Create the shop with no plan
        $shop = factory($this->model)->create();
        $hostValue = base64_encode($shop->getDomain()->toNative().'/admin');
        // Setup API stub
        $this->setApiStub();
        ApiStub::stubResponses(['graphql_app_subscription_create']);

        $result = call_user_func(
            $this->action,
            $shop->getId(),
            NullablePlanId::fromNative(null),
            $hostValue
        );

        $this->assertStringContainsString('/admin/charges/4019552312/confirm_recurring_application_charge', $result);
        // One recurring line item billed every year: the plan's usage cap is left out
        $lineItems = ApiStub::$graphRequests[0]['variables']['lineItems'];
        $this->assertCount(1, $lineItems);
        $this->assertSame('ANNUAL', $lineItems[0]['plan']['appRecurringPricingDetails']['interval']);
    }

    /**
     * @dataProvider refusedPlanProvider
     */
    public function testRefusedPlanThrowsApiException(array $states, string $mutation, string $fixture): void
    {
        factory(Util::getShopifyConfig('models.plan', Plan::class))->states(array_merge(['installable'], $states))->create();
        $shop = factory($this->model)->create();
        $this->setApiStub();
        ApiStub::stubResponses([$fixture]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage("{$mutation} failed: returnUrl: Return url is invalid");

        call_user_func(
            $this->action,
            $shop->getId(),
            NullablePlanId::fromNative(null),
            base64_encode($shop->getDomain()->toNative().'/admin')
        );
    }

    public static function refusedPlanProvider(): array
    {
        return [
            'monthly' => [['type_recurring'], 'appSubscriptionCreate', 'graphql_app_subscription_create_user_errors'],
            'annual' => [['type_recurring', 'interval_annual'], 'appSubscriptionCreate', 'graphql_app_subscription_create_user_errors'],
            'one-time' => [['type_onetime'], 'appPurchaseOneTimeCreate', 'graphql_app_purchase_one_time_create_user_errors'],
        ];
    }
}
