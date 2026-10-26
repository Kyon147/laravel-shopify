<?php

namespace Osiset\ShopifyApp\Test\Http\Middleware;

use Illuminate\Auth\AuthManager;
use Illuminate\Http\Request;
use Osiset\ShopifyApp\Http\Middleware\Billable as BillableMiddleware;
use Osiset\ShopifyApp\Objects\Values\NullableSessionId;
use Osiset\ShopifyApp\Objects\Values\SessionContext;
use Osiset\ShopifyApp\Objects\Values\SessionToken;
use Osiset\ShopifyApp\Storage\Models\Charge;
use Osiset\ShopifyApp\Storage\Models\Plan;
use Osiset\ShopifyApp\Test\TestCase;
use Osiset\ShopifyApp\Util;

class BillableTest extends TestCase
{
    /**
     * @var AuthManager
     */
    protected $auth;

    public function setUp(): void
    {
        parent::setUp();

        $this->auth = $this->app->make(AuthManager::class);
    }

    public function testEnabledBillingWithUnpaidShop(): void
    {
        // Enable billing and set a shop
        $shop = factory($this->model)->create();
        $this->auth->login($shop);
        $this->app['config']->set('shopify-app.billing_enabled', true);

        // Run the middleware
        $result = $this->runMiddleware(BillableMiddleware::class);

        // Assert it was not called and redirect happened
        $this->assertFalse($result[0]);
        $this->assertNotFalse(strpos($result[1], 'Redirecting to http://localhost/billing'));
    }

    public function testEnabledBillingWithPaidShop(): void
    {
        // Enable billing and set a shop
        $plan = factory(Util::getShopifyConfig('models.plan', Plan::class))->states('type_recurring')->create();
        $shop = factory($this->model)->create([
            'plan_id' => $plan->getId()->toNative(),
        ]);
        factory(Util::getShopifyConfig('models.charge', Charge::class))->states('type_recurring')->create([
            'plan_id' => $plan->getId()->toNative(),
            'user_id' => $shop->getId()->toNative(),
        ]);

        $this->auth->login($shop);
        $this->app['config']->set('shopify-app.billing_enabled', true);

        // Run the middleware
        $result = $this->runMiddleware(BillableMiddleware::class);

        // Assert it was called
        $this->assertTrue($result[0]);
    }

    public function testEnabledBillingWithGrandfatheredShop(): void
    {
        // Enable billing and set a shop
        $shop = factory($this->model)->states('grandfathered')->create();
        $this->auth->login($shop);
        $this->app['config']->set('shopify-app.billing_enabled', true);

        // Run the middleware
        $result = $this->runMiddleware(BillableMiddleware::class);

        // Assert it was called
        $this->assertTrue($result[0]);
    }

    public function testEnabledBillingWithFreemiumShop(): void
    {
        // Enable billing and set a shop
        $shop = factory($this->model)->states('freemium')->create();
        $this->auth->login($shop);
        $this->app['config']->set('shopify-app.billing_enabled', true);

        // Run the middleware
        $result = $this->runMiddleware(BillableMiddleware::class);

        // Assert it was called
        $this->assertTrue($result[0]);
    }

    public function testDisabledBillingShouldPassOn(): void
    {
        // Ensure billing is disabled and set a shop
        $shop = factory($this->model)->create();
        $this->auth->login($shop);
        $this->app['config']->set('shopify-app.billing_enabled', false);

        // Run the middleware
        $result = $this->runMiddleware(BillableMiddleware::class);

        $this->assertTrue($result[0]);
    }

    public function testNoNativeAppBridgeAndNoAjaxRequest(): void
    {
        $plan = factory(Util::getShopifyConfig('models.plan', Plan::class))->states('type_recurring')->create();
        $shop = factory($this->model)->create(['plan_id' => $plan->getId()->toNative()]);

        factory(Util::getShopifyConfig('models.charge', Charge::class))->states('type_recurring')->create(
            [
                'plan_id' => $plan->getId()->toNative(),
                'user_id' => $shop->getId()->toNative(),
            ]
        );

        $this->auth->login($shop);
        $this->app['config']->set('shopify-app.billing_enabled', true);
        $this->app['config']->set('shopify-app.frontend_engine', 'REACT');

        $request = Request::create('/test', 'GET');

        $this->assertFalse($request->ajax());

        $result = $this->runMiddleware(BillableMiddleware::class, $request);

        $this->assertTrue($result[0]);
    }

    public function testAjaxRequest(): void
    {
        $shop = factory($this->model)->create();

        $this->auth->login($shop);
        $this->app['config']->set('shopify-app.billing_enabled', true);
        $this->app['config']->set('shopify-app.frontend_engine', 'REACT');

        $request = Request::create('/test', 'GET');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $this->assertTrue($request->ajax());

        $response = $this->runMiddleware(BillableMiddleware::class, $request);

        $this->assertFalse($response[0]);
        $this->assertEquals(402, $response[1]->getStatusCode());
        $this->assertArrayHasKey('forceRedirectUrl', $response[1]->getData(true));
    }

    /**
     * Log in a shop the way VerifyShopify does, with the token the request was verified with.
     */
    protected function loginWithToken(string $token)
    {
        $shop = factory($this->model)->create();
        $shop->setSessionContext(new SessionContext(
            SessionToken::fromNative($token),
            NullableSessionId::fromNative(null),
            $shop->getAccessToken()
        ));
        $this->auth->login($shop);
        $this->app['config']->set('shopify-app.billing_enabled', true);

        return $shop;
    }

    protected function billingRedirectQuery(array $query): array
    {
        [$called, $response] = $this->runMiddleware(BillableMiddleware::class, Request::create('/', 'GET', $query));

        $this->assertFalse($called);
        parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $redirectQuery);

        return $redirectQuery;
    }

    public function testRedirectDropsSignedLoadParamsAndForwardsIdToken(): void
    {
        $token = $this->buildToken();
        $this->loginWithToken($token);

        $query = $this->billingRedirectQuery([
            'embedded' => '1',
            'hmac' => 'deadbeef',
            'host' => 'abc',
            'id_token' => $token,
            'timestamp' => '1337178173',
        ]);

        $this->assertArrayNotHasKey('hmac', $query);
        $this->assertArrayNotHasKey('timestamp', $query);
        $this->assertArrayNotHasKey('id_token', $query);
        $this->assertSame($token, $query['token']);
        $this->assertSame('1', $query['embedded']);
    }

    public function testRedirectDoesNotForwardAnIdTokenThatWasNotUsed(): void
    {
        // Verified with a bearer token; the id_token in the query was ignored
        $this->loginWithToken($this->buildToken());

        $query = $this->billingRedirectQuery([
            'host' => 'abc',
            'id_token' => $this->buildToken(['jti' => 'stale']),
        ]);

        $this->assertArrayNotHasKey('id_token', $query);
        $this->assertArrayNotHasKey('token', $query);
    }
}
