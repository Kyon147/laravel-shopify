<?php

namespace Osiset\ShopifyApp\Test\Traits;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\View;
use Osiset\ShopifyApp\Exceptions\MissingShopDomainException;
use Osiset\ShopifyApp\Messaging\Events\ShopAuthenticatedEvent;
use Osiset\ShopifyApp\Test\Stubs\Api as ApiStub;
use Osiset\ShopifyApp\Test\TestCase;
use Osiset\ShopifyApp\Util;

class AuthControllerTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // Setup API stub
        $this->setApiStub();
    }

    public function testAuthRedirectsToShopifyWhenNoCode(): void
    {
        Event::fake();
        // Run the request
        $response = $this->call('post', '/authenticate', ['shop' => 'example.myshopify.com']);

        // Check the redirect happens and location is set properly in the header.
        $response->assertViewHas('shopDomain', 'example.myshopify.com');
        $response->assertViewHas(
            'url',
            'https://example.myshopify.com/admin/oauth/authorize?client_id='.Util::getShopifyConfig('api_key').'&scope=read_products%2Cwrite_products%2Cread_themes&redirect_uri=https%3A%2F%2Flocalhost%2Fauthenticate'
        );

        Event::assertDispatched(ShopAuthenticatedEvent::class);
    }

    public function testAuthAcceptsShopWithCode(): void
    {
        // Stub the responses
        ApiStub::stubResponses(['access_token_grant']);

        // HMAC for regular tests
        $hmac = '6f16da24e8185e717f22a3373a1928fcaea7ea2401be40ab0d160f5bed7fe55a';
        $hmacParams = [
            'hmac' => $hmac,
            'shop' => 'example.myshopify.com',
            'code' => '1234678',
            'timestamp' => '1337178173',
        ];

        $response = $this->call('get', '/authenticate', $hmacParams);
        $response->assertRedirect();
    }

    public function testAuthThrowExceptionForBadHmac(): void
    {
        // Stub the responses
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

        // Call authenticate with no parameters
        $this->call('get', '/authenticate');
    }

    public function testTokenRejectsExternalTarget(): void
    {
        $response = $this->call('get', '/authenticate/token', [
            'shop' => 'example.myshopify.com',
            'target' => 'https://evil.com',
            'host' => 'ZXhhbXBsZS5teXNob3BpZnkuY29t',
        ]);

        $response->assertViewHas('target', function (string $target): bool {
            return ! str_contains($target, 'evil.com');
        });

        $response->assertViewHas('target', function (string $target): bool {
            return str_starts_with($target, '/?shop=example.myshopify.com');
        });
    }

    public function testTokenAcceptsRelativeTarget(): void
    {
        $response = $this->call('get', '/authenticate/token', [
            'shop' => 'example.myshopify.com',
            'target' => '/orders',
            'host' => 'ZXhhbXBsZS5teXNob3BpZnkuY29t',
        ]);

        $response->assertViewHas('target', function (string $target): bool {
            return str_starts_with($target, '/orders?shop=example.myshopify.com');
        });
    }

    public function testTokenStripsSessionTokensFromTarget(): void
    {
        $response = $this->call('get', '/authenticate/token', [
            'shop' => 'example.myshopify.com',
            'target' => '/orders?embedded=1&id_token=old.id.token&token=old.session.token',
            'host' => 'ZXhhbXBsZS5teXNob3BpZnkuY29t',
        ]);

        $response->assertViewHas('target', function (string $target): bool {
            return str_starts_with($target, '/orders?')
                && ! str_contains($target, 'id_token')
                && ! str_contains($target, 'old.session.token');
        });
    }

    public function testTokenShowsTheDefaultLoadingSkeleton(): void
    {
        $response = $this->call('get', '/authenticate/token', [
            'shop' => 'example.myshopify.com',
            'target' => '/orders',
            'host' => 'ZXhhbXBsZS5teXNob3BpZnkuY29t',
        ]);

        $response->assertSee('Polaris-SkeletonPage__Page', false);
        $response->assertSee('@keyframes shimmer', false);
        $response->assertSee('shopify.idToken()', false);
    }

    public function testTokenLoadingStateCanBeOverriddenWithoutTheScript(): void
    {
        // Mimic an app override in resources/views/vendor/shopify-app.
        $path = sys_get_temp_dir().'/shopify-app-token-loading-'.uniqid();
        File::ensureDirectoryExists($path.'/partials');
        File::put($path.'/partials/token_loading.blade.php', '<div class="app-loading">Loading</div>');
        File::put($path.'/partials/token_loading_styles.blade.php', '<style>.app-loading { margin: 0; }</style>');
        View::prependNamespace('shopify-app', $path);

        try {
            $response = $this->call('get', '/authenticate/token', [
                'shop' => 'example.myshopify.com',
                'target' => '/orders',
                'host' => 'ZXhhbXBsZS5teXNob3BpZnkuY29t',
            ]);

            $response->assertSee('<div class="app-loading">Loading</div>', false);
            $response->assertSee('<style>.app-loading { margin: 0; }</style>', false);
            $response->assertDontSee('Polaris-SkeletonPage', false);
            $response->assertDontSee('@keyframes shimmer', false);

            // The package's token handoff still runs.
            $response->assertSee('shopify.idToken()', false);
            $response->assertViewHas('target', function (string $target): bool {
                return str_starts_with($target, '/orders?shop=example.myshopify.com');
            });
        } finally {
            File::deleteDirectory($path);
        }
    }
}
