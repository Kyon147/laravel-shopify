<?php

namespace Osiset\ShopifyApp\Test;

use Illuminate\Support\Facades\Config;
use LogicException;
use Osiset\ShopifyApp\Util;
use stdClass;

class UtilTest extends TestCase
{
    public function testHmacCreator(): void
    {
        // Set the secret to use for HMAC creations
        $secret = 'hello';

        // Raw data
        $data = 'one-two-three';
        $this->assertSame(
            hash_hmac('sha256', $data, $secret, true),
            Util::createHmac(['data' => $data, 'raw' => true], $secret)->toNative()
        );

        // Raw data encoded
        $data = 'one-two-three';
        $this->assertSame(
            base64_encode(hash_hmac('sha256', $data, $secret, true)),
            Util::createHmac(['data' => $data, 'raw' => true, 'encode' => true], $secret)->toNative()
        );

        // Query build (sorts array and builds query string)
        $data = ['one' => 1, 'two' => 2, 'three' => 3];
        $this->assertSame(
            hash_hmac('sha256', 'one=1three=3two=2', $secret, false),
            Util::createHmac(['data' => $data, 'buildQuery' => true], $secret)->toNative()
        );
    }

    public function testRegisterPackageRoutes(): void
    {
        $this->expectExceptionObject(new LogicException('Excluded routes must be an array', 0));

        // Routes to exclude
        $routes = explode(',', 'home,billing');

        $this->assertTrue(Util::registerPackageRoute('authenticate', false));
        $this->assertTrue(Util::registerPackageRoute('authenticate', []));
        $this->assertTrue(Util::registerPackageRoute('authenticate', $routes));
        $this->assertFalse(Util::registerPackageRoute('home', $routes));

        Util::registerPackageRoute('home', stdClass::class);
    }

    public function testRouteNames(): void
    {
        // non-dot-notation route name
        $this->assertSame(
            Util::getShopifyConfig('route_names.home'),
            'home'
        );

        // dot-notation route name
        $this->assertSame(
            Util::getShopifyConfig('route_names.authenticate.token'),
            'authenticate.token'
        );
    }

    public function testGetShopifyConfig(): void
    {
        $this->app['config']->set('shopify-app.config_api_callback', function (string $key, $shop) {
            if ($key === 'api_secret') {
                return 'hello world';
            }

            return Config::get("shopify-app.{$key}");
        });

        $secret = Util::getShopifyConfig('api_secret');
        $grantMode = Util::getShopifyConfig('api_grant_mode');

        $this->assertSame('hello world', $secret);
        $this->assertSame('OFFLINE', $grantMode);
    }

    public function testGraphQLWebhookTopic(): void
    {
        // REST-format topics are changed to the GraphQL format
        $topics = [
            'app/uninstalled' => 'APP_UNINSTALLED',
            'orders/partially_fulfilled' => 'ORDERS_PARTIALLY_FULFILLED',
            'order_transactions/create' => 'ORDER_TRANSACTIONS_CREATE',
        ];

        foreach ($topics as $restTopic => $graphQLTopic) {
            $this->assertEquals(
                $graphQLTopic,
                Util::getGraphQLWebhookTopic($restTopic)
            );
        }

        // GraphQL-format topics are unchanged
        $this->assertEquals(
            'ORDERS_PARTIALLY_FULFILLED',
            Util::getGraphQLWebhookTopic('ORDERS_PARTIALLY_FULFILLED')
        );
    }

    public function testIsMPAApplicationIsTrue(): void
    {
        $this->app['config']->set('shopify-app.frontend_type', 'MPA');

        $result = Util::isMPAApplication();

        $this->assertTrue($result);
    }

    public function testIsMPAApplicationIsFalse(): void
    {
        $this->app['config']->set('shopify-app.frontend_type', 'SPA');

        $result = Util::isMPAApplication();

        $this->assertFalse($result);
    }

    public function testHasAppLegacySupport(): void
    {
        $supportedFeatures = $this->app['config']->get('shopify-app.app_legacy_supports', []);
        foreach ($supportedFeatures as $feature => $val) {
            $this->assertSame(
                $val,
                Util::hasAppLegacySupport($feature)
            );
        }
    }

    /**
     * @dataProvider sanitizeTokenRedirectTargetProvider
     */
    public function testSanitizeTokenRedirectTarget(?string $target, string $origin, string $expected): void
    {
        $this->assertSame($expected, Util::sanitizeTokenRedirectTarget($target, $origin));
    }

    public static function sanitizeTokenRedirectTargetProvider(): array
    {
        return [
            'null target' => [null, 'http://localhost', '/'],
            'empty target' => ['', 'http://localhost', '/'],
            'relative path' => ['/orders', 'http://localhost', '/orders'],
            'protocol relative' => ['//evil.com', 'http://localhost', '/'],
            'javascript scheme' => ['javascript:alert(1)', 'http://localhost', '/'],
            'external https' => ['https://evil.com', 'http://localhost', '/'],
            'same origin absolute' => ['http://localhost/orders', 'http://localhost', '/orders'],
            'same origin absolute with query' => ['http://localhost/orders?foo=bar', 'http://localhost', '/orders?foo=bar'],
            'port mismatch' => ['http://localhost:8080/foo', 'http://localhost', '/'],
        ];
    }

    public function testGetOfflineTokenExcludedShopDomainsEmptyWhenMissing(): void
    {
        $this->app['config']->set('shopify-app.offline_token_excluded_shops', null);

        $this->assertSame([], Util::getOfflineTokenExcludedShopDomains());
    }

    public function testGetOfflineTokenExcludedShopDomainsNormalizesArray(): void
    {
        $this->app['config']->set('shopify-app.offline_token_excluded_shops', [
            ' Placeholder.myshopify.com ',
            'other.myshopify.com',
            'placeholder.myshopify.com',
            '',
        ]);

        $this->assertSame(
            ['placeholder.myshopify.com', 'other.myshopify.com'],
            Util::getOfflineTokenExcludedShopDomains()
        );
    }

    public function testGetOfflineTokenExcludedShopDomainsNormalizesCommaString(): void
    {
        $this->app['config']->set(
            'shopify-app.offline_token_excluded_shops',
            ' Placeholder.myshopify.com , other.myshopify.com,Placeholder.myshopify.com '
        );

        $this->assertSame(
            ['placeholder.myshopify.com', 'other.myshopify.com'],
            Util::getOfflineTokenExcludedShopDomains()
        );
    }

    public function testShopIsExcludedFromOfflineTokenLifecycle(): void
    {
        $this->app['config']->set('shopify-app.offline_token_excluded_shops', [
            'placeholder.myshopify.com',
        ]);

        $excluded = factory($this->model)->create(['name' => 'Placeholder.myshopify.com']);
        $included = factory($this->model)->create(['name' => 'real-shop.myshopify.com']);

        $this->assertTrue(Util::shopIsExcludedFromOfflineTokenLifecycle($excluded));
        $this->assertFalse(Util::shopIsExcludedFromOfflineTokenLifecycle($included));
    }
}
