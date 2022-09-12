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

    public function testUseNativeAppBridgeIsTrue(): void
    {
        $this->app['config']->set('shopify-app.frontend_engine', 'VUE');

        $result = Util::useNativeAppBridge();

        $this->assertTrue($result);
    }

    public function testUseNativeAppBridgeIsFalse(): void
    {
        $this->app['config']->set('shopify-app.frontend_engine', 'REACT');

        $result = Util::useNativeAppBridge();

        $this->assertFalse($result);
    }
}
