<?php

namespace Osiset\ShopifyApp\Test\Http\Middleware;

use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Request;
use Osiset\ShopifyApp\Http\Middleware\AuthWebhook as AuthWebhookMiddleware;
use Osiset\ShopifyApp\Test\TestCase;

class AuthWebhookTest extends TestCase
{
    public function testDenysForMissingShopHeader(): void
    {
        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            // This valid referer should be ignored as there is a get variable
            array_merge(Request::server(), [
                'HTTP_X_Shopify_Hmac_Sha256' => '1234',
            ])
        );

        // Run the middleware
        $result = $this->runMiddleware(AuthWebhookMiddleware::class, $newRequest);

        // Assert we get a proper response
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $result[1]->status());
    }

    public function testDenysForMissingHmacHeader(): void
    {
        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            // This valid referer should be ignored as there is a get variable
            array_merge(Request::server(), [
                'HTTP_X_Shopify_Shop_Domain' => 'example.myshopify.com',
            ])
        );

        // Run the middleware
        $result = $this->runMiddleware(AuthWebhookMiddleware::class, $newRequest);

        // Assert we get a proper response
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $result[1]->status());
    }

    public function testRuns(): void
    {
        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            // This valid referer should be ignored as there is a get variable
            array_merge(Request::server(), [
                'HTTP_X_Shopify_Hmac_Sha256' => 'vhwRQys0GZozEzsl9+bjquUnCkjSE7r1YPgl9S0CY1E=',
                'HTTP_X_Shopify_Shop_Domain' => 'example.myshopify.com',
            ])
        );

        // Run the middleware
        $result = $this->runMiddleware(AuthWebhookMiddleware::class, $newRequest);

        // Assert we get a proper response
        $this->assertTrue($result[0]);
    }

    public function testRunsWithOldSecretDuringRotation(): void
    {
        $request = $this->webhookRequest($this->signWebhook());
        $this->rotateApiSecret();

        // Run the middleware
        $result = $this->runMiddleware(AuthWebhookMiddleware::class, $request);

        $this->assertTrue($result[0]);
    }

    public function testDenysOldSecretWhenNotConfigured(): void
    {
        $request = $this->webhookRequest($this->signWebhook());
        $this->rotateApiSecret(false);

        // Run the middleware
        $result = $this->runMiddleware(AuthWebhookMiddleware::class, $request);

        $this->assertFalse($result[0]);
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $result[1]->status());
    }

    public function testDenysInvalidHmacWithOldSecretConfigured(): void
    {
        $request = $this->webhookRequest(base64_encode(str_repeat('0', 32)));
        $this->rotateApiSecret();

        // Run the middleware
        $result = $this->runMiddleware(AuthWebhookMiddleware::class, $request);

        $this->assertFalse($result[0]);
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $result[1]->status());
    }

    protected function signWebhook(): string
    {
        return base64_encode(hash_hmac('sha256', Request::instance()->getContent(), $this->app['config']->get('shopify-app.api_secret'), true));
    }

    protected function webhookRequest(string $hmac): HttpRequest
    {
        return Request::instance()->duplicate(
            [],
            null,
            null,
            null,
            null,
            array_merge(Request::server(), [
                'HTTP_X_Shopify_Hmac_Sha256' => $hmac,
                'HTTP_X_Shopify_Shop_Domain' => 'example.myshopify.com',
            ])
        );
    }
}
