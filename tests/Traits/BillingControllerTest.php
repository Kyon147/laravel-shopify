<?php

namespace Osiset\ShopifyApp\Test\Traits;

use Illuminate\Support\Str;
use Osiset\ShopifyApp\Contracts\ShopModel;
use Osiset\ShopifyApp\Storage\Models\Charge;
use Osiset\ShopifyApp\Storage\Models\Plan;
use Osiset\ShopifyApp\Test\Stubs\Api as ApiStub;
use Osiset\ShopifyApp\Test\Stubs\BillingApi;
use Osiset\ShopifyApp\Test\TestCase;
use Osiset\ShopifyApp\Util;

class BillingControllerTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // Stub in our API class
        $this->setApiStub();
    }

    public function testSendsShopToBillingScreen(): void
    {
        // Stub the responses
        ApiStub::stubResponses([
            'post_recurring_application_charges',
            'post_recurring_application_charges_activate',
        ]);

        // Create the shop and log them in
        $shop = factory($this->model)->create();
        $this->authenticateShop($shop);

        // Create a on-install plan
        factory(Util::getShopifyConfig('models.plan', Plan::class))->states('type_recurring', 'installable')->create();

        // Run the call
        $response = $this->call('get', '/billing', ['shop' => $shop->getDomain()->toNative()]);

        $response->assertOk();
        $response->assertViewHas(
            'url',
            'https://example.myshopify.com/admin/charges/1029266947/confirm_recurring_application_charge?signature=BAhpBANeWT0%3D--64de8739eb1e63a8f848382bb757b20343eb414f'
        );
    }

    public function testReactFrontendShopAcceptsBilling(): void
    {
        BillingApi::install();

        config(['shopify-app.frontend_type' => 'SPA']);

        // Create the shop and log them in
        $shop = factory($this->model)->create();
        $this->authenticateShop($shop);
        $hostValue = base64_encode($shop->getDomain()->toNative().'/admin');
        // Make the plan
        $plan = factory(Util::getShopifyConfig('models.plan', Plan::class))->states('type_recurring')->create();

        $this->call('get', '/billing/'.$plan->id, ['shop' => $shop->name, 'host' => $hostValue])->assertOk();
        BillingApi::approve($shop->name, 1001);

        // Run the call
        $response = $this->call(
            'get',
            "/billing/process/{$plan->id}",
            [
                'charge_id' => 1001,
                'shop' => $shop->getDomain()->toNative(),
                'host' => $hostValue,
            ]
        );

        // Refresh the model
        $shop->refresh();


        // Assert we've redirected and shop has been updated
        $response->assertRedirect();
        $this->assertTrue(Str::contains($response->headers->get('Location'), '&host='.urlencode($hostValue)));
        $this->assertTrue(Str::contains($response->headers->get('Location'), '&billing=success'));
        $this->assertNotNull($shop->plan);
    }

    public function testBladeFrontendShopAcceptsBilling(): void
    {
        BillingApi::install();

        config(['shopify-app.frontend_type' => 'MPA']);

        // Create the shop and log them in
        $shop = factory($this->model)->create();
        $this->authenticateShop($shop);

        // Make the plan
        $plan = factory(Util::getShopifyConfig('models.plan', Plan::class))->states('type_recurring')->create();
        $hostValue = urlencode(base64_encode($shop->getDomain()->toNative().'/admin'));
        $this->call('get', '/billing/'.$plan->id, ['shop' => $shop->name, 'host' => $hostValue])->assertOk();
        BillingApi::approve($shop->name, 1001);
        // Run the call
        $response = $this->call(
            'get',
            "/billing/process/{$plan->id}",
            [
                'charge_id' => 1001,
                'shop' => $shop->getDomain()->toNative(),
                'host' => $hostValue,
            ]
        );

        // Refresh the model
        $shop->refresh();

        // Assert we've redirected and shop has been updated
        $response->assertRedirect();
        $this->assertTrue(Str::contains($response->headers->get('Location'), '&host='.$hostValue));
        $this->assertFalse(Str::contains($response->headers->get('Location'), '&billing=success'));
        $this->assertNotNull($shop->plan);
    }

    public function testUsageChargeSuccess(): void
    {
        // Stub the responses
        ApiStub::stubResponses([
            'post_recurring_application_charges_usage_charges_alt',
            'post_recurring_application_charges_usage_charges_alt2',
        ]);

        // Create the shop
        $plan = factory(Util::getShopifyConfig('models.plan', Plan::class))->states('type_recurring')->create();
        $shop = factory($this->model)->create([
            'plan_id' => $plan->getId()->toNative(),
        ]);
        factory(Util::getShopifyConfig('models.charge', Charge::class))->states('type_recurring')->create([
            'plan_id' => $plan->getId()->toNative(),
            'user_id' => $shop->getId()->toNative(),
        ]);

        // Login the shop
        $this->authenticateShop($shop);

        // Setup the data for the usage charge and the signature for it
        $secret = $this->app['config']->get('shopify-app.api_secret');
        $data = ['description' => 'One email', 'price' => 1.00, 'redirect' => 'https://localhost/usage-success'];
        $signature = Util::createHmac(['data' => $data, 'buildQuery' => true], $secret);

        // Run the call
        $response = $this->call(
            'post',
            '/billing/usage-charge',
            array_merge($data, ['signature' => $signature->toNative(), 'shop' => $shop->name])
        );
        $response->assertRedirect($data['redirect']);
        $response->assertSessionHas('success');

        // Run again with no redirect
        $data = ['description' => 'One email', 'price' => 1.00];
        $signature = Util::createHmac(['data' => $data, 'buildQuery' => true], $secret);

        // Run the call
        $response = $this->call(
            'post',
            '/billing/usage-charge',
            array_merge($data, ['signature' => $signature->toNative(), 'shop' => $shop->name])
        );
        $response->assertRedirect('http://localhost');
        $response->assertSessionHas('success');
    }

    public function testReturnToSettingScreenNoPlan(): void
    {
        // Set up a shop
        $shop = factory($this->model)->create([
            'plan_id' => null,
        ]);
        //Log in
        $this->authenticateShop($shop);
        $hostValue = urlencode(base64_encode($shop->getDomain()->toNative().'/admin'));
        $url = 'https://example-app.com/billing/process/9999?shop='.$shop->name.'&host='.$hostValue;
        // Try to go to bill without a charge id which happens when you cancel the charge
        $response = $this->call(
            'get',
            $url,
            [
                'shop' => $shop->name,
                'host' => $hostValue,
            ]
        );
        //Confirm we get sent back to the homepage of the app
        $response->assertRedirect('https://example-app.com?shop='.$shop->name.'&host='.$hostValue);
    }

    public function testUsageChargeSuccessWithShopParam(): void
    {
        // Stub the responses
        ApiStub::stubResponses([
            'post_recurring_application_charges_usage_charges_alt',
        ]);

        // Create the shop
        $plan = factory(Util::getShopifyConfig('models.plan', Plan::class))->states('type_recurring')->create();
        $shop = factory($this->model)->create([
            'plan_id' => $plan->getId()->toNative(),
        ]);
        factory(Util::getShopifyConfig('models.charge', Charge::class))->states('type_recurring')->create([
            'plan_id' => $plan->getId()->toNative(),
            'user_id' => $shop->getId()->toNative(),
        ]);

        // Login the shop
        $this->authenticateShop($shop);

        // Set up the data for the usage charge and the signature for it
        $secret = $this->app['config']->get('shopify-app.api_secret');
        $data = ['description' => 'One email', 'price' => 1.00, 'redirect' => 'https://localhost/usage-success'];
        $signature = Util::createHmac(['data' => $data, 'buildQuery' => true], $secret);

        // Run the call
        $response = $this->call(
            'post',
            '/billing/usage-charge',
            array_merge($data, ['signature' => $signature->toNative(), 'shop' => $shop->name])
        );
        $response->assertRedirect($data['redirect']);
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('charges', ['description' => 'One email']);
    }

    public function testUsageChargeUsesAuthenticatedShopWithoutShopParam(): void
    {
        // Stub the responses
        ApiStub::stubResponses([
            'post_recurring_application_charges_usage_charges_alt',
        ]);

        // Create the shop
        $plan = factory(Util::getShopifyConfig('models.plan', Plan::class))
            ->states('type_recurring')->create();
        $shop = factory($this->model)->create([
            'plan_id' => $plan->getId()->toNative(),
        ]);
        factory(Util::getShopifyConfig('models.charge', Charge::class))
            ->states('type_recurring')->create([
                'plan_id' => $plan->getId()->toNative(),
                'user_id' => $shop->getId()->toNative(),
            ]);

        // Login the shop
        $this->authenticateShop($shop);

        // Set up the data for the usage charge and the signature for it
        $secret = $this->app['config']->get('shopify-app.api_secret');
        $data = [
            'description' => 'One email',
            'price' => 1.00,
            'redirect' => 'https://localhost/usage-success',
        ];
        $signature = Util::createHmac(['data' => $data, 'buildQuery' => true], $secret);

        // Run the call
        $response = $this->call(
            'post',
            '/billing/usage-charge',
            array_merge($data, ['signature' => $signature->toNative()])
        );
        $response->assertRedirect($data['redirect']);
        $this->assertDatabaseHas('charges', ['description' => 'One email', 'user_id' => $shop->id]);
    }

    protected function authenticateShop(ShopModel $shop): void
    {
        $domain = $shop->getDomain()->toNative();
        $this->withServerVariables([
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->buildToken([
                'dest' => 'https://'.$domain,
                'iss' => 'https://'.$domain.'/admin',
            ]),
        ]);
    }
}
