<?php

namespace Osiset\ShopifyApp\Test\Services;

use Exception;
use Gnikyt\BasicShopifyAPI\BasicShopifyAPI;
use Gnikyt\BasicShopifyAPI\ResponseAccess;
use Gnikyt\BasicShopifyAPI\Session;
use Illuminate\Support\Arr;
use Mockery;
use Osiset\ShopifyApp\Contracts\ApiHelper as IApiHelper;
use Osiset\ShopifyApp\Contracts\ShopModel as IShopModel;
use Osiset\ShopifyApp\Exceptions\ApiException;
use Osiset\ShopifyApp\Objects\Enums\AuthMode;
use Osiset\ShopifyApp\Objects\Enums\ChargeType;
use Osiset\ShopifyApp\Objects\Enums\PlanInterval;
use Osiset\ShopifyApp\Objects\Transfers\PlanDetails as PlanDetailsTransfer;
use Osiset\ShopifyApp\Objects\Transfers\UsageChargeDetails as UsageChargeDetailsTransfer;
use Osiset\ShopifyApp\Objects\Values\ChargeReference;
use Osiset\ShopifyApp\Services\ApiHelper;
use Osiset\ShopifyApp\Test\Stubs\Api as ApiStub;
use Osiset\ShopifyApp\Test\TestCase;
use Osiset\ShopifyApp\Util;

class ApiHelperTest extends TestCase
{
    /**
     * @var \Osiset\ShopifyApp\Contracts\ApiHelper
     */
    protected $api;

    public function setUp(): void
    {
        parent::setUp();

        $this->api = $this->app->make(IApiHelper::class);
    }

    public function testMake(): void
    {
        // Cover the full make
        $this->app['config']->set('shopify-app.api_rate_limiting_enabled', true);

        // Make it
        $api = $this->api->make()->getApi();

        $this->assertInstanceOf(BasicShopifyAPI::class, $api);
        $this->assertSame(Util::getShopifyConfig('api_secret'), $this->app['config']->get('shopify-app.api_secret'));
        $this->assertSame(Util::getShopifyConfig('api_key'), $this->app['config']->get('shopify-app.api_key'));
        $this->assertSame($this->app['config']->get('shopify-app.api_version'), '2024-04');
    }

    public function testSetAndGetApi(): void
    {
        // Make it and set it
        $api = $this->api->make();
        $this->api->setApi($api->getApi());

        $this->assertInstanceOf(BasicShopifyAPI::class, $this->api->getApi());
    }

    public function testWithApi(): void
    {
        // Make it and set it
        $api = $this->api->make();

        // Use it
        $called = false;
        $this->api->withApi($api->getApi(), function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function testBuildAuthUrl(): void
    {
        // Create a shop
        $shop = factory($this->model)->create();

        $this->assertNotEmpty(
            $shop->apiHelper()->buildAuthUrl(AuthMode::OFFLINE(), 'read_content')
        );
    }

    public function testGetScriptTags(): void
    {
        // Create a shop
        $shop = factory($this->model)->create();

        // Response stubbing
        $this->setApiStub();
        ApiStub::stubResponses(['get_script_tags']);

        $data = $shop->apiHelper()->getScriptTags();
        $this->assertInstanceOf(ResponseAccess::class, $data);
        $this->assertSame('onload', $data[0]['event']);
        $this->assertCount(2, $data);
    }

    public function testCreateScriptTags(): void
    {
        // Create a shop
        $shop = factory($this->model)->create();

        // Response stubbing
        $this->setApiStub();
        ApiStub::stubResponses(['empty']);

        $data = $shop->apiHelper()->createScriptTag([]);
        $this->assertInstanceOf(ResponseAccess::class, $data);
    }

    public function testDeleteScriptTag(): void
    {
        // Create a shop
        $shop = factory($this->model)->create();

        // Response stubbing
        $this->setApiStub();
        ApiStub::stubResponses(['empty']);

        $this->assertInstanceOf(
            ResponseAccess::class,
            $shop->apiHelper()->deleteScriptTag(1)
        );
    }

    public function testGetCharge(): void
    {
        // Create a shop
        $shop = factory($this->model)->create();

        // Response stubbing
        $this->setApiStub();
        ApiStub::stubResponses(['get_application_charge']);

        // A one-time charge is read over REST, the only API that returns its return URL
        $data = $shop->apiHelper()->getCharge(ChargeType::CHARGE(), ChargeReference::fromNative(1234));
        $this->assertInstanceOf(ResponseAccess::class, $data);
        $this->assertSame('iPod Cleaning', $data->name);
        $this->assertSame('accepted', $data['status']);
        $this->assertSame('http://google.com', $data['return_url']);
        $this->assertSame([], ApiStub::$graphRequests);
    }

    public function testGetRecurringCharge(): void
    {
        // Create a shop
        $shop = factory($this->model)->create();

        // Response stubbing
        $this->setApiStub();
        ApiStub::stubResponses(['graphql_app_subscription_node']);

        $data = $shop->apiHelper()->getCharge(ChargeType::RECURRING(), ChargeReference::fromNative(1029266947));
        $this->assertSame(
            [
                'id' => 1029266947,
                'name' => 'Super Mega Plan',
                'status' => 'active',
                'test' => false,
                'trial_days' => 7,
                'return_url' => 'https://example-app.com/billing/process/1?shop=example.myshopify.com',
                'created_at' => '2026-11-16T10:00:00Z',
                'billing_on' => '2026-12-23T10:00:00Z',
                'activated_on' => null,
                'trial_ends_on' => null,
                'cancelled_on' => null,
                'price' => '15.0',
                'currency' => 'USD',
                'capped_amount' => '100.0',
                'terms' => 'One email',
                'balance_used' => 11.0,
                'balance_remaining' => 89.0,
            ],
            $data->toArray()
        );
        // REST-era callers reading these keys directly get null instead of an error
        $this->assertNull($data['activated_on']);
        $this->assertSame(
            'gid://shopify/AppSubscription/1029266947',
            ApiStub::$graphRequests[0]['variables']['id']
        );
    }

    public function testGetChargeThatDoesNotExistThrows(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Charge gid://shopify/AppSubscription/1 was not found.');

        // Create a shop
        $shop = factory($this->model)->create();

        // Response stubbing
        $this->setApiStub();
        ApiStub::stubResponses(['graphql_node_not_found']);

        $shop->apiHelper()->getCharge(ChargeType::RECURRING(), ChargeReference::fromNative(1));
    }

    public function testActivateCharge(): void
    {
        // Create a shop
        $shop = factory($this->model)->create();

        // Response stubbing
        $this->setApiStub();
        ApiStub::stubResponses(['graphql_app_subscription_node']);

        $data = $shop->apiHelper()->activateCharge(ChargeType::RECURRING(), ChargeReference::fromNative(1029266947));
        $this->assertInstanceOf(ResponseAccess::class, $data);
        $this->assertSame('Super Mega Plan', $data['name']);
        $this->assertSame('active', $data['status']);
        // Only a read: the GraphQL Admin API has no activation mutation
        $this->assertCount(1, ApiStub::$graphRequests);
        $this->assertStringContainsString('query appSubscription', ApiStub::$graphRequests[0]['query']);
    }

    public function testGraphQLRequestFailureThrowsApiExceptionWithTheCause(): void
    {
        $cause = new Exception('cURL error 28: Operation timed out');
        $api = Mockery::mock(BasicShopifyAPI::class);
        $api->shouldReceive('graph')->once()->andReturn([
            'errors' => true,
            'response' => null,
            'status' => null,
            'body' => null,
            'exception' => $cause,
        ]);

        try {
            (new ApiHelper())->setApi($api)->getCharge(ChargeType::RECURRING(), ChargeReference::fromNative(1));
            $this->fail('A failed GraphQL request must throw.');
        } catch (ApiException $exception) {
            $this->assertSame('cURL error 28: Operation timed out', $exception->getMessage());
            $this->assertSame($cause, $exception->getPrevious());
        }
    }

    public function testGraphQLRequestFailureUsesShopifysErrorMessage(): void
    {
        $api = Mockery::mock(BasicShopifyAPI::class);
        $api->shouldReceive('graph')->once()->andReturn([
            'errors' => true,
            'response' => null,
            'status' => 429,
            'body' => [['message' => 'Throttled']],
            'exception' => new Exception('Client error: 429 Too Many Requests'),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Throttled');

        (new ApiHelper())->setApi($api)->getCharge(ChargeType::RECURRING(), ChargeReference::fromNative(1));
    }

    public function testCreateCharge(): void
    {
        // Create a shop
        $shop = factory($this->model)->create();

        // Response stubbing
        $this->setApiStub();
        ApiStub::stubResponses(['graphql_app_subscription_create']);

        // Build the details object
        $transfer = new PlanDetailsTransfer();
        $transfer->name = 'Test';
        $transfer->price = 12.00;
        $transfer->interval = PlanInterval::EVERY_30_DAYS()->toNative();
        $transfer->test = true;
        $transfer->trialDays = 7;
        $transfer->cappedAmount = 100.00;
        $transfer->terms = 'One email';
        $transfer->returnUrl = 'https://example-app.com/billing/process/1';

        $data = $shop->apiHelper()->createCharge(
            ChargeType::RECURRING(),
            $transfer
        );
        $this->assertInstanceOf(ResponseAccess::class, $data);
        $this->assertSame(4019552312, $data['id']);
        $this->assertSame('pending', $data['status']);
        $this->assertSame('Test', $data['name']);
        $this->assertStringContainsString('/admin/charges/4019552312/', $data['confirmation_url']);

        $this->assertStringContainsString('mutation appSubscriptionCreate', ApiStub::$graphRequests[0]['query']);
        $this->assertSame(
            [
                'name' => 'Test',
                'returnUrl' => 'https://example-app.com/billing/process/1',
                'trialDays' => 7,
                'test' => true,
                'lineItems' => [
                    ['plan' => ['appRecurringPricingDetails' => [
                        'price' => ['amount' => 12.00, 'currencyCode' => 'USD'],
                        'interval' => 'EVERY_30_DAYS',
                    ]]],
                    ['plan' => ['appUsagePricingDetails' => [
                        'cappedAmount' => ['amount' => 100.00, 'currencyCode' => 'USD'],
                        'terms' => 'One email',
                    ]]],
                ],
            ],
            ApiStub::$graphRequests[0]['variables']
        );
    }

    public function testCreateAnnualChargeHasNoUsagePricing(): void
    {
        // Create a shop
        $shop = factory($this->model)->create();

        // Response stubbing
        $this->setApiStub();
        ApiStub::stubResponses(['graphql_app_subscription_create']);

        // Build the details object
        $transfer = new PlanDetailsTransfer();
        $transfer->name = 'Test';
        $transfer->price = 120.00;
        $transfer->interval = PlanInterval::ANNUAL()->toNative();
        $transfer->test = false;
        $transfer->trialDays = 0;
        $transfer->cappedAmount = 100.00;
        $transfer->terms = 'One email';
        $transfer->returnUrl = 'https://example-app.com/billing/process/1';

        $shop->apiHelper()->createCharge(ChargeType::RECURRING(), $transfer);

        $this->assertSame(
            [['plan' => ['appRecurringPricingDetails' => [
                'price' => ['amount' => 120.00, 'currencyCode' => 'USD'],
                'interval' => 'ANNUAL',
            ]]]],
            ApiStub::$graphRequests[0]['variables']['lineItems']
        );
    }

    public function testCreateOneTimeCharge(): void
    {
        // Create a shop
        $shop = factory($this->model)->create();

        // Response stubbing
        $this->setApiStub();
        ApiStub::stubResponses(['graphql_app_purchase_one_time_create']);

        // Build the details object
        $transfer = new PlanDetailsTransfer();
        $transfer->name = 'Test';
        $transfer->price = 25.00;
        $transfer->test = false;
        $transfer->returnUrl = 'https://example-app.com/billing/process/2';

        $data = $shop->apiHelper()->createCharge(ChargeType::CHARGE(), $transfer);
        $this->assertSame(1017262351, $data['id']);
        $this->assertStringContainsString('/admin/charges/1017262351/', $data['confirmation_url']);

        $this->assertStringContainsString('mutation appPurchaseOneTimeCreate', ApiStub::$graphRequests[0]['query']);
        $this->assertSame(
            [
                'name' => 'Test',
                'price' => ['amount' => 25.00, 'currencyCode' => 'USD'],
                'returnUrl' => 'https://example-app.com/billing/process/2',
                'test' => false,
            ],
            ApiStub::$graphRequests[0]['variables']
        );
    }

    public function testCreateChargeThrowsWhenShopifyRefusesIt(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('appSubscriptionCreate failed: returnUrl: Return url is invalid');

        // Create a shop
        $shop = factory($this->model)->create();

        // Response stubbing
        $this->setApiStub();
        ApiStub::stubResponses(['graphql_app_subscription_create_user_errors']);

        // Build the details object
        $transfer = new PlanDetailsTransfer();
        $transfer->name = 'Test';
        $transfer->price = 12.00;
        $transfer->interval = PlanInterval::EVERY_30_DAYS()->toNative();
        $transfer->test = true;
        $transfer->returnUrl = 'not-a-url';

        $shop->apiHelper()->createCharge(ChargeType::RECURRING(), $transfer);
    }

    public function testCreateChargeGraphQLKeepsItsPayload(): void
    {
        // Create a shop
        $shop = factory($this->model)->create();

        // Response stubbing
        $this->setApiStub();
        ApiStub::stubResponses(['graphql_app_subscription_create']);

        // Build the details object
        $transfer = new PlanDetailsTransfer();
        $transfer->name = 'Test';
        $transfer->price = 120.00;
        $transfer->interval = PlanInterval::ANNUAL()->toNative();
        $transfer->test = false;
        $transfer->returnUrl = 'https://example-app.com/billing/process/1';

        $data = $shop->apiHelper()->createChargeGraphQL($transfer);
        $this->assertSame('gid://shopify/AppSubscription/4019552312', $data['appSubscription']['id']);
        $this->assertStringContainsString('/admin/charges/4019552312/', $data['confirmationUrl']);
    }

    public function testGetWebhooks(): void
    {
        // Create a shop
        $shop = factory($this->model)->create();

        // Response stubbing
        $this->setApiStub();
        ApiStub::stubResponses(['get_webhooks']);

        $data = $shop->apiHelper()->getWebhooks();
        $this->assertInstanceOf(ResponseAccess::class, $data);
        $this->assertTrue(count($data) > 0);
    }

    public function testCreateWebhook(): void
    {
        // Create a shop
        /** @var IShopModel $shop */
        $shop = factory($this->model)->create();

        // Response stubbing
        $this->setApiStub();
        ApiStub::stubResponses(['post_webhook']);

        $data = $shop->apiHelper()->createWebhook([
            'topic' => 'ORDERS_CREATE',
            'address' => 'https://localhost/webhook/orders-create',
        ]);
        $this->assertInstanceOf(ResponseAccess::class, $data);
        $this->assertSame('ORDERS_CREATE', $data['data']['webhookSubscriptionCreate']['topic']);
    }

    public function testCreateWebhookThrowsOnUserErrors(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('webhookSubscriptionCreate failed: webhookSubscription.callbackUrl: Address must use a valid domain name');

        // Create a shop
        $shop = factory($this->model)->create();

        // Response stubbing
        $this->setApiStub();
        ApiStub::stubResponses(['post_webhook_user_errors']);

        $shop->apiHelper()->createWebhook([
            'topic' => 'ORDERS_CREATE',
            'address' => 'https://shop.test/webhook/orders-create',
        ]);
    }

    public function testDeleteWebhookThrowsOnUserErrors(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('webhookSubscriptionDelete failed: id: Access denied for webhookSubscriptionDelete field.');

        // Create a shop
        $shop = factory($this->model)->create();

        // Response stubbing
        $this->setApiStub();
        ApiStub::stubResponses(['delete_webhook_user_errors']);

        $shop->apiHelper()->deleteWebhook('gid://shopify/WebhookSubscription/1');
    }

    public function testDeleteWebhookIgnoresAlreadyDeletedSubscription(): void
    {
        // Create a shop
        $shop = factory($this->model)->create();

        // Response stubbing
        $this->setApiStub();
        ApiStub::stubResponses(['delete_webhook_not_found']);

        $data = $shop->apiHelper()->deleteWebhook('gid://shopify/WebhookSubscription/1');

        $this->assertNull($data['data']['webhookSubscriptionDelete']['deletedWebhookSubscriptionId']);
    }

    public function testDeleteWebhook(): void
    {
        // Create a shop
        $shop = factory($this->model)->create();

        // Response stubbing
        $this->setApiStub();
        ApiStub::stubResponses(['empty']);

        $this->assertInstanceOf(
            ResponseAccess::class,
            $shop->apiHelper()->deleteWebhook(1)
        );
    }

    public function testCreateUsageCharge(): void
    {
        // Create a shop
        $shop = factory($this->model)->create();

        // Response stubbing
        $this->setApiStub();
        ApiStub::stubResponses(['graphql_app_subscription_node', 'graphql_app_usage_record_create']);

        $transfer = new UsageChargeDetailsTransfer();
        $transfer->chargeReference = ChargeReference::fromNative(1029266947);
        $transfer->price = 12.00;
        $transfer->description = 'Hello!';

        $data = $shop->apiHelper()->createUsageCharge($transfer);
        $this->assertInstanceOf(ResponseAccess::class, $data);
        $this->assertSame(1034618208, $data['id']);
        $this->assertSame(23.0, $data['balance_used']);
        $this->assertSame(77.0, $data['balance_remaining']);

        // The record belongs to the subscription's usage line item, in the cap's currency
        $this->assertSame(
            'gid://shopify/AppSubscription/1029266947',
            ApiStub::$graphRequests[0]['variables']['id']
        );
        $this->assertStringContainsString('mutation appUsageRecordCreate', ApiStub::$graphRequests[1]['query']);
        $this->assertSame(
            [
                'subscriptionLineItemId' => 'gid://shopify/AppSubscriptionLineItem/1029266947?v=1&index=1',
                'price' => ['amount' => 12.00, 'currencyCode' => 'USD'],
                'description' => 'Hello!',
            ],
            ApiStub::$graphRequests[1]['variables']
        );
    }

    public function testCreateUsageChargeThrowsWhenShopifyRefusesIt(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('appUsageRecordCreate failed: price: Total price exceeds balance remaining');

        // Create a shop
        $shop = factory($this->model)->create();

        // Response stubbing
        $this->setApiStub();
        ApiStub::stubResponses(['graphql_app_subscription_node', 'graphql_app_usage_record_create_user_errors']);

        $transfer = new UsageChargeDetailsTransfer();
        $transfer->chargeReference = ChargeReference::fromNative(1029266947);
        $transfer->price = 500.00;
        $transfer->description = 'Hello!';

        $shop->apiHelper()->createUsageCharge($transfer);
    }

    public function testCreateUsageChargeWithoutUsagePricingThrows(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('The subscription has no usage pricing to charge against.');

        // Create a shop
        $shop = factory($this->model)->create();

        // Response stubbing
        $this->setApiStub();
        ApiStub::stubResponses(['graphql_app_subscription_node_without_usage']);

        $transfer = new UsageChargeDetailsTransfer();
        $transfer->chargeReference = ChargeReference::fromNative(1029266948);
        $transfer->price = 1.00;
        $transfer->description = 'Hello!';

        $shop->apiHelper()->createUsageCharge($transfer);
    }

    public function testErrors(): void
    {
        $this->expectExceptionObject(new Exception('Error!', 0));

        // Create a shop
        $shop = factory($this->model)->create();

        // Response stubbing
        $this->setApiStub();
        ApiStub::stubResponses(['empty_with_error_graphql']);

        $transfer = new PlanDetailsTransfer();
        $transfer->name = 'Test';
        $transfer->price = 12.00;
        $transfer->interval = PlanInterval::ANNUAL()->toNative();
        $transfer->test = true;
        $transfer->trialDays = 7;

        $shop->apiHelper()->createChargeGraphQL($transfer);
    }

    public function testOauthAccessTokenPostThrowsApiExceptionOnErrorResponse(): void
    {
        $shop = factory($this->model)->create();

        $this->setApiStub();
        ApiStub::stubResponses(['oauth_access_token_error']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unknown error');

        $session = new Session($shop->name, $shop->password);
        $this->app->make(IApiHelper::class)->make($session)->refreshOfflineAccessToken('any_refresh_token');
    }

    public function testOauthAccessTokenPostUsesStringBodyInApiExceptionWhenPresent(): void
    {
        $shop = factory($this->model)->create();

        $this->setApiStub();
        ApiStub::stubResponses(['oauth_access_token_error_string_body']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('invalid_client');

        $session = new Session($shop->name, $shop->password);
        $this->app->make(IApiHelper::class)->make($session)->refreshOfflineAccessToken('any_refresh_token');
    }

    /**
     * Sign params the way Shopify does: sorted key=value pairs joined with "&",
     * with "%" and "&" escaped in keys and values, and "=" also escaped in keys.
     */
    protected function shopifySign(array $params): array
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = strtr($key, ['%' => '%25', '&' => '%26', '=' => '%3D']).'='.strtr($value, ['%' => '%25', '&' => '%26']);
        }
        $params['hmac'] = hash_hmac('sha256', implode('&', $pairs), $this->app['config']->get('shopify-app.api_secret'));

        return $params;
    }

    protected function signedParams(array $extra = []): array
    {
        return $this->shopifySign(array_merge([
            'host' => 'YWRtaW4uc2hvcGlmeS5jb20vc3RvcmUvbXlzdG9yZQ',
            'shop' => 'mystore.myshopify.com',
            'timestamp' => '1337178173',
        ], $extra));
    }

    public function testVerifyRequestWithPlainParams(): void
    {
        $this->assertTrue($this->api->make()->verifyRequest($this->signedParams(['code' => 'abc123'])));
    }

    public function testVerifyRequestWithAmpersandInValue(): void
    {
        $params = $this->signedParams(['target' => '/billing/process/1?host=abc&charge_id=111']);

        $this->assertTrue($this->api->make()->verifyRequest($params));
    }

    public function testVerifyRequestRejectsValueChangedAfterSigning(): void
    {
        $params = $this->signedParams(['target' => '/billing/process/1?host=abc&charge_id=111']);
        $params['target'] = '/billing/process/1?host=abc&charge_id=222';

        $this->assertFalse($this->api->make()->verifyRequest($params));
    }

    public function testVerifyRequestWithPercentInValue(): void
    {
        $params = $this->signedParams(['target' => '/search?q=50%25+off&note=100%']);

        $this->assertTrue($this->api->make()->verifyRequest($params));
    }

    public function testVerifyRequestWithSpecialCharsInKey(): void
    {
        $params = $this->signedParams(['a=b&c%d' => 'value']);

        $this->assertTrue($this->api->make()->verifyRequest($params));
    }

    public function testVerifyRequestRejectsWrongHmac(): void
    {
        $params = $this->signedParams();
        $params['hmac'] = str_repeat('0', 64);

        $this->assertFalse($this->api->make()->verifyRequest($params));
    }

    public function testVerifyRequestRequiresShopTimestampAndHmac(): void
    {
        $api = $this->api->make();
        $params = $this->signedParams();

        foreach (['shop', 'timestamp', 'hmac'] as $key) {
            $this->assertFalse($api->verifyRequest(Arr::except($params, $key)));
        }
    }

    public function testVerifyRequestRejectsNonStringHmac(): void
    {
        $params = $this->signedParams();
        $params['hmac'] = [$params['hmac']];

        $this->assertFalse($this->api->make()->verifyRequest($params));
    }
}
