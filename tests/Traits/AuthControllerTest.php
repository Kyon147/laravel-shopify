<?php

namespace Osiset\ShopifyApp\Test\Traits;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Osiset\ShopifyApp\Exceptions\MissingShopDomainException;
use Osiset\ShopifyApp\Messaging\Events\ShopAuthenticatedEvent;
use Osiset\ShopifyApp\Objects\Enums\AuthStrategy;
use Osiset\ShopifyApp\Test\Stubs\Api as ApiStub;
use Osiset\ShopifyApp\Test\TestCase;
use Osiset\ShopifyApp\Util;

class AuthControllerTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config()->set('shopify-app.auth_strategy', AuthStrategy::AUTH_CODE_FLOW);
        $this->setApiStub();
    }

    public function testAuthRedirectsToShopifyWhenNoCode(): void
    {
        Event::fake();

        $response = $this->call('post', '/authenticate', ['shop' => 'example.myshopify.com']);

        $response->assertViewHas('shopDomain', 'example.myshopify.com');
        $response->assertViewHas(
            'url',
            'https://example.myshopify.com/admin/oauth/authorize?client_id='.Util::getShopifyConfig('api_key').'&scope=read_products%2Cwrite_products%2Cread_themes&redirect_uri=https%3A%2F%2Flocalhost%2Fauthenticate'
        );
        Event::assertNotDispatched(ShopAuthenticatedEvent::class);
    }

    public function testAuthAcceptsShopWithCode(): void
    {
        Event::fake();
        ApiStub::stubResponses(['access_token_grant']);
        $hmacParams = [
            'hmac' => '6f16da24e8185e717f22a3373a1928fcaea7ea2401be40ab0d160f5bed7fe55a',
            'shop' => 'example.myshopify.com',
            'code' => '1234678',
            'timestamp' => '1337178173',
        ];

        $response = $this->call('get', '/authenticate', $hmacParams);

        $response->assertRedirect();
        Event::assertDispatched(ShopAuthenticatedEvent::class);
    }

    public function testAuthThrowExceptionForBadHmac(): void
    {
        ApiStub::stubResponses(['access_token_grant']);
        $hmacParams = [
            'hmac' => 'badhmac',
            'shop' => 'example.myshopify.com',
            'code' => '1234678',
            'timestamp' => '1337178173',
        ];

        $response = $this->call('get', '/authenticate', $hmacParams);

        $response->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    public function testAuthThrowExceptionForMissingShopAndAuthenticatedUser(): void
    {
        $this->withoutExceptionHandling();
        $this->expectException(MissingShopDomainException::class);

        $this->call('get', '/authenticate');
    }
}
