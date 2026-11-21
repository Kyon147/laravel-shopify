<?php

namespace Osiset\ShopifyApp\Test\Actions;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Request;
use Osiset\ShopifyApp\Actions\AfterAuthorize;
use Osiset\ShopifyApp\Actions\AuthenticateShop;
use Osiset\ShopifyApp\Actions\DispatchScripts;
use Osiset\ShopifyApp\Actions\DispatchWebhooks;
use Osiset\ShopifyApp\Actions\InstallShop;
use Osiset\ShopifyApp\Exceptions\ApiException;
use Osiset\ShopifyApp\Messaging\Events\AppInstalledEvent;
use Osiset\ShopifyApp\Objects\Enums\ThemeSupportLevel;
use Osiset\ShopifyApp\Objects\Values\ShopId;
use Osiset\ShopifyApp\Test\Stubs\Api as ApiStub;
use Osiset\ShopifyApp\Test\TestCase;

class AuthenticateShopTest extends TestCase
{
    /**
     * @var \Osiset\ShopifyApp\Actions\AuthenticateShop
     */
    protected $action;

    public function setUp(): void
    {
        parent::setUp();

        $this->action = $this->app->make(AuthenticateShop::class);
    }

    public function testShouldGoToLoginForInvalid(): void
    {
        // Build request
        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [
                'shop' => 'some-shop.myshopify.com',
            ],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            Request::server()
        );
        Request::swap($newRequest);

        // Run the action
        [, $status] = call_user_func($this->action, $newRequest);

        $this->assertFalse($status);
    }

    public function testShouldGoToAuthRedirectForInvalidHmac(): void
    {
        // Build request
        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [
                'shop' => 'mystore123.myshopify.com',
                'hmac' => 'badhmac',
                'timestamp' => '1565631587',
                'code' => '123',
                'locale' => 'de',
                'state' => '3.14',
            ],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            Request::server()
        );
        Request::swap($newRequest);

        // Setup API stub
        $this->setApiStub();
        ApiStub::stubResponses(['access_token']);

        // Run the action
        [, $status] = call_user_func($this->action, $newRequest);

        $this->assertNull($status);
    }

    public function testRuns(): void
    {
        Event::fake();
        // Build request
        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [
                'shop' => 'mystore123.myshopify.com',
                'hmac' => '3d9768c9cc44b8bd66125cb82b6a59a3d835432f560d19b3f79b9fc696ef6396',
                'timestamp' => '1565631587',
                'code' => '123',
                'locale' => 'de',
                'state' => '3.14',
            ],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            Request::server()
        );
        Request::swap($newRequest);

        // Setup API stub
        $this->setApiStub();
        ApiStub::stubResponses(['access_token']);

        // Run the action
        [, $status] = call_user_func($this->action, $newRequest);

        $this->assertTrue($status);
        Event::assertDispatched(AppInstalledEvent::class);
    }

    public function testCompletesInstallWhenWebhookInstallFails(): void
    {
        Event::fake();

        // On the sync queue the installer runs inline, so its exception reaches this action.
        $this->mock(DispatchWebhooks::class, function ($mock) {
            $mock->shouldReceive('__invoke')->once()->andThrow(new ApiException('webhookSubscriptionCreate failed'));
        });
        $this->action = $this->app->make(AuthenticateShop::class);

        // Build request
        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [
                'shop' => 'mystore123.myshopify.com',
                'hmac' => '3d9768c9cc44b8bd66125cb82b6a59a3d835432f560d19b3f79b9fc696ef6396',
                'timestamp' => '1565631587',
                'code' => '123',
                'locale' => 'de',
                'state' => '3.14',
            ],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            Request::server()
        );
        Request::swap($newRequest);

        // Setup API stub
        $this->setApiStub();
        ApiStub::stubResponses(['access_token']);

        // Run the action
        [, $status] = call_user_func($this->action, $newRequest);

        $this->assertTrue($status);
        Event::assertDispatched(AppInstalledEvent::class);
    }

    /**
     * A published config may list the levels as enum cases (as the shipped config did before
     * native enums) or as their integer values. Both must still dispatch the script tags.
     *
     * @dataProvider unacceptableLevelsProvider
     */
    public function testDispatchesScriptsForUnacceptableThemeLevel(array $unacceptableLevels): void
    {
        Event::fake();
        $this->app['config']->set('shopify-app.theme_support.unacceptable_levels', $unacceptableLevels);

        $this->mock(InstallShop::class, function ($mock) {
            $mock->shouldReceive('__invoke')->once()->andReturn([
                'completed' => true,
                'url' => null,
                'shop_id' => ShopId::fromNative(1),
                'theme_support_level' => ThemeSupportLevel::UNSUPPORTED->value,
            ]);
        });
        $this->mock(DispatchScripts::class, function ($mock) {
            $mock->shouldReceive('__invoke')->once();
        });
        $this->mock(DispatchWebhooks::class, function ($mock) {
            $mock->shouldReceive('__invoke')->once();
        });
        $this->mock(AfterAuthorize::class, function ($mock) {
            $mock->shouldReceive('__invoke')->once();
        });
        $this->action = $this->app->make(AuthenticateShop::class);

        $request = Request::instance()->duplicate(['shop' => 'mystore123.myshopify.com']);

        [, $status] = call_user_func($this->action, $request);

        $this->assertTrue($status);
    }

    public static function unacceptableLevelsProvider(): array
    {
        return [
            'enum case' => [[ThemeSupportLevel::UNSUPPORTED]],
            'integer value' => [[ThemeSupportLevel::UNSUPPORTED->value]],
        ];
    }

    public function testManagedAppInstall(): void
    {
        Event::fake();
        // Build request
        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [
                'shop' => 'mystore123.myshopify.com',
                'host' => 'dfsdf343df3433dd3453453dfdf',
                'id_token' => '3d9768c9cc44b8bd66125cb82b6a59a3d835432f560d19b3f79b9fc696ef6396',
                'locale' => 'de',
            ],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            Request::server()
        );
        Request::swap($newRequest);

        // Setup API stub
        $this->setApiStub();
        ApiStub::stubResponses(['access_token']);

        // Run the action
        [, $status] = call_user_func($this->action, $newRequest);

        $this->assertTrue($status);
        Event::assertDispatched(AppInstalledEvent::class);
    }
}
