<?php

namespace Osiset\ShopifyApp\Test\Traits;

use Illuminate\Http\Request;
use Mockery;
use Osiset\ShopifyApp\Actions\GetPlanUrl;
use Osiset\ShopifyApp\Http\Controllers\BillingController;
use Osiset\ShopifyApp\Objects\Values\NullablePlanId;
use Osiset\ShopifyApp\Objects\Values\NullableSessionId;
use Osiset\ShopifyApp\Objects\Values\SessionContext;
use Osiset\ShopifyApp\Objects\Values\SessionToken;
use Osiset\ShopifyApp\Storage\Models\Charge;
use Osiset\ShopifyApp\Storage\Models\Plan;
use Osiset\ShopifyApp\Storage\Queries\Shop as ShopQuery;
use Osiset\ShopifyApp\Test\Stubs\Api as ApiStub;
use Osiset\ShopifyApp\Test\Stubs\BillingApi;
use Osiset\ShopifyApp\Test\TestCase;
use Osiset\ShopifyApp\Util;

class BillingTenantTest extends TestCase
{
    /**
     * @dataProvider billingRouteProvider
     */
    public function testBillingRejectsAnotherStoresSession(string $uri): void
    {
        $this->setApiStub();
        factory($this->model)->create(['name' => 'shop-name.myshopify.com']);
        $victim = factory($this->model)->create(['name' => 'victim.myshopify.com', 'plan_id' => null]);
        $data = ['price' => 1, 'description' => 'One email'];
        $signature = Util::createHmac(['data' => $data, 'buildQuery' => true], Util::getShopifyConfig('api_secret'));
        $query = array_merge($data, [
            'shop' => $victim->name,
            'signature' => $signature->toNative(),
            'charge_id' => 1,
            'host' => base64_encode($victim->name.'/admin'),
        ]);

        $response = $this->getJson($uri.'?'.http_build_query($query), [
            'Authorization' => 'Bearer '.$this->buildToken(),
        ]);

        $response->assertForbidden();
        $response->assertExactJson(['error' => 'Request shop does not match session token.']);
        $this->assertNull($victim->fresh()->plan_id);
        $this->assertDatabaseCount('charges', 0);
    }

    public static function billingRouteProvider(): array
    {
        return [
            'create charge' => ['/billing'],
            'activate plan' => ['/billing/process/1'],
            'create usage charge' => ['/billing/usage-charge'],
        ];
    }

    public function testSignedUsageChargeCannotBeRetargetedToAnotherStore(): void
    {
        $this->setApiStub();
        ApiStub::stubResponses(['post_recurring_application_charges_usage_charges']);
        factory($this->model)->create(['name' => 'shop-name.myshopify.com']);
        $plan = factory(Plan::class)->states('type_recurring')->create();
        $victim = factory($this->model)->create(['name' => 'victim.myshopify.com', 'plan_id' => $plan->id]);
        $charge = factory(Charge::class)->states('type_recurring')->create([
            'charge_id' => 12345, 'plan_id' => $plan->id, 'user_id' => $victim->id,
        ]);
        $data = ['price' => 1, 'description' => 'One email'];
        $signature = Util::createHmac(['data' => $data, 'buildQuery' => true], Util::getShopifyConfig('api_secret'));

        $response = $this->getJson('/billing/usage-charge?'.http_build_query(array_merge($data, [
            'signature' => $signature->toNative(), 'shop' => $victim->name,
        ])), ['Authorization' => 'Bearer '.$this->buildToken()]);

        $response->assertForbidden();
        $response->assertExactJson(['error' => 'Request shop does not match session token.']);
        $this->assertSame($plan->id, (int) $victim->fresh()->plan_id);
        $this->assertDatabaseCount('charges', 1);
        $this->assertDatabaseMissing('charges', ['charge_id' => 1034618208]);
        $this->assertSame($charge->status, $charge->fresh()->status);
        $this->assertSame(['post_recurring_application_charges_usage_charges'], ApiStub::$stubFiles);
    }

    /**
     * @dataProvider frontendProvider
     */
    public function testBillingCallbackFetchesTokenBeforeActivatingPlan(string $frontend): void
    {
        BillingApi::install();
        config(['shopify-app.frontend_type' => $frontend]);
        $shop = factory($this->model)->create(['name' => 'shop-name.myshopify.com', 'plan_id' => null]);
        $plan = factory(Util::getShopifyConfig('models.plan', Plan::class))->states('type_recurring')->create();
        $query = [
            'shop' => $shop->name,
            'charge_id' => 1001,
            'host' => base64_encode($shop->name.'/admin'),
        ];
        ($this->app->make(GetPlanUrl::class))($shop->getId(), NullablePlanId::fromNative($plan->id), $query['host']);
        BillingApi::approve($shop->name, 1001);

        $response = $this->get('/billing/process/'.$plan->id.'?'.http_build_query($query));

        $response->assertRedirect();
        $this->assertStringContainsString('/authenticate/token', $response->headers->get('Location'));
        $this->assertNull($shop->fresh()->plan_id);
        $this->assertGuest();

        $bootstrap = $this->get($response->headers->get('Location'));
        $bootstrap->assertViewIs('shopify-app::auth.token');
        $target = $bootstrap->viewData('target');
        $this->assertStringStartsWith('/billing/process/'.$plan->id.'?', $target);
        $this->assertStringContainsString('charge_id=1001', $target);

        // App Bridge returns a new token; only this authenticated request may activate the plan.
        $result = $this->get($target.'&token='.$this->buildToken());

        $result->assertRedirect();
        $this->assertSame($plan->id, (int) $shop->fresh()->plan_id);
        $this->assertDatabaseCount('charges', 1);
    }

    public static function frontendProvider(): array
    {
        return [
            'MPA' => ['MPA'],
            'SPA' => ['SPA'],
        ];
    }

    public function testCustomBillingRouteRejectsCookieOnlyAuthentication(): void
    {
        $shop = factory($this->model)->create();
        $this->actingAs($shop);
        $this->app['router']->get('/custom-billing', BillingController::class.'@index');

        $response = $this->getJson('/custom-billing?shop='.$shop->name);

        $response->assertForbidden();
        $response->assertExactJson(['error' => 'A valid shop session is required.']);
    }

    public function testBillingControllerSelectsStoreFromAuthenticatedSession(): void
    {
        $shop = factory($this->model)->create(['name' => 'shop-name.myshopify.com']);
        $victim = factory($this->model)->create(['name' => 'victim.myshopify.com']);
        $shop->setSessionContext(new SessionContext(
            SessionToken::fromNative($this->buildToken()),
            NullableSessionId::fromNative(null),
            $shop->getAccessToken()
        ));
        $request = Request::create('/custom-billing', 'GET', [
            'shop' => $victim->name,
            'host' => base64_encode($shop->name.'/admin'),
        ]);
        $request->setUserResolver(fn () => $shop);
        $getPlanUrl = Mockery::mock(GetPlanUrl::class);
        $getPlanUrl->shouldReceive('__invoke')->once()
            ->withArgs(fn ($id) => $id->toNative() === $shop->id)
            ->andReturn('https://shop-name.myshopify.com/confirm');

        $view = (new BillingController())->index($request, $this->app->make(ShopQuery::class), $getPlanUrl);

        $this->assertSame('https://shop-name.myshopify.com/confirm', $view->getData()['url']);
    }
}
