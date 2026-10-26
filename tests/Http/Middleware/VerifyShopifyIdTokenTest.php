<?php

namespace Osiset\ShopifyApp\Test\Http\Middleware;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Osiset\ShopifyApp\Exceptions\HttpException;
use Osiset\ShopifyApp\Exceptions\SignatureVerificationException;
use Osiset\ShopifyApp\Http\Middleware\VerifyShopify;
use Osiset\ShopifyApp\Test\TestCase;

class VerifyShopifyIdTokenTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        factory($this->model)->create(['name' => 'shop-name.myshopify.com']);
    }

    /**
     * Build the query Shopify sends on an embedded app load, signed with the app secret.
     */
    protected function signedLoad(array $overrides = []): array
    {
        $query = array_merge([
            'embedded' => '1',
            'host' => 'YWRtaW4uc2hvcGlmeS5jb20vc3RvcmUvc2hvcC1uYW1l',
            'id_token' => $this->buildToken(),
            'locale' => 'en',
            'session' => 'abc123',
            'shop' => 'shop-name.myshopify.com',
            'timestamp' => (string) $this->now->getTimestamp(),
        ], $overrides);

        return $this->sign($query);
    }

    protected function sign(array $query): array
    {
        ksort($query);
        $query['hmac'] = hash_hmac('sha256', urldecode(http_build_query($query)), $this->app['config']->get('shopify-app.api_secret'));

        return $query;
    }

    protected function makeRequest(array $query, string $method = 'GET', array $server = []): Request
    {
        $request = Request::create('/orders', $method, [], [], [], $server);
        $request->query->replace($query);

        return $request;
    }

    public static function frontendTypeProvider(): array
    {
        return [
            'MPA' => ['MPA'],
            'SPA' => ['SPA'],
        ];
    }

    /**
     * @dataProvider frontendTypeProvider
     */
    public function testSignedLoadWithIdTokenLogsInWithoutRedirect(string $frontendType): void
    {
        $this->app['config']->set('shopify-app.frontend_type', $frontendType);

        [$called] = $this->runMiddleware(VerifyShopify::class, $this->makeRequest($this->signedLoad()));

        $this->assertTrue($called);
        $this->assertSame('shop-name.myshopify.com', Auth::user()->name);
    }

    public function testTamperedIdTokenFailsHmac(): void
    {
        $query = $this->signedLoad();
        $query['id_token'] = $this->buildToken(['jti' => 'other']);

        $this->expectException(SignatureVerificationException::class);
        $this->runMiddleware(VerifyShopify::class, $this->makeRequest($query));
    }

    public function testUnsignedIdTokenIsIgnored(): void
    {
        $request = $this->makeRequest(['id_token' => $this->buildToken(), 'shop' => 'shop-name.myshopify.com']);

        [$called, $response] = $this->runMiddleware(VerifyShopify::class, $request);

        $this->assertFalse($called);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('/authenticate/token', $response->getTargetUrl());
        $this->assertGuest();
    }

    public function testHmacFromRefererDoesNotEnableIdToken(): void
    {
        $signed = $this->sign(['shop' => 'shop-name.myshopify.com', 'timestamp' => (string) $this->now->getTimestamp()]);
        $request = $this->makeRequest(
            ['id_token' => $this->buildToken(), 'shop' => 'shop-name.myshopify.com'],
            'GET',
            ['HTTP_REFERER' => 'https://admin.shopify.com/?'.http_build_query($signed)]
        );

        [$called] = $this->runMiddleware(VerifyShopify::class, $request);

        $this->assertFalse($called);
        $this->assertGuest();
    }

    public function testEmptyQueryHmacWithSignedRefererDoesNotEnableIdToken(): void
    {
        // ?hmac= is normalised to null, so the signed Referer is what gets verified
        $signed = $this->sign(['shop' => 'shop-name.myshopify.com', 'timestamp' => (string) $this->now->getTimestamp()]);
        $request = $this->makeRequest(
            ['hmac' => null, 'id_token' => $this->buildToken(), 'shop' => 'shop-name.myshopify.com'],
            'GET',
            ['HTTP_REFERER' => 'https://admin.shopify.com/?'.http_build_query($signed)]
        );

        [$called] = $this->runMiddleware(VerifyShopify::class, $request);

        $this->assertFalse($called);
        $this->assertGuest();
    }

    public function testExpiredIdTokenRedirectsWithoutLeakingIt(): void
    {
        $expired = $this->buildToken([
            'exp' => $this->now->getTimestamp() - 120,
            'nbf' => $this->now->getTimestamp() - 180,
            'iat' => $this->now->getTimestamp() - 180,
        ]);

        [$called, $response] = $this->runMiddleware(VerifyShopify::class, $this->makeRequest($this->signedLoad(['id_token' => $expired])));

        $this->assertFalse($called);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $url = $response->getTargetUrl();
        $this->assertStringContainsString('/authenticate/token', $url);
        $this->assertStringNotContainsString('id_token', urldecode($url));
        $this->assertStringNotContainsString($expired, urldecode($url));
        $this->assertGuest();
    }

    public function testExpiredIdTokenOnApiRequestIsForbidden(): void
    {
        $expired = $this->buildToken([
            'exp' => $this->now->getTimestamp() - 120,
            'nbf' => $this->now->getTimestamp() - 180,
            'iat' => $this->now->getTimestamp() - 180,
        ]);
        $request = $this->makeRequest($this->signedLoad(['id_token' => $expired]), 'GET', ['HTTP_ACCEPT' => 'application/json']);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(403);
        $this->runMiddleware(VerifyShopify::class, $request);
    }

    public function testIdTokenSignedWithWrongSecretDoesNotLogIn(): void
    {
        $secret = $this->app['config']->get('shopify-app.api_secret');
        $this->app['config']->set('shopify-app.api_secret', 'other-secret');
        $forged = $this->buildToken();
        $this->app['config']->set('shopify-app.api_secret', $secret);
        $query = $this->signedLoad(['id_token' => $forged]);

        [$called] = $this->runMiddleware(VerifyShopify::class, $this->makeRequest($query));

        $this->assertFalse($called);
        $this->assertGuest();
    }

    public function testIdTokenForAnotherShopIsRejected(): void
    {
        factory($this->model)->create(['name' => 'victim.myshopify.com']);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(403);
        $this->runMiddleware(VerifyShopify::class, $this->makeRequest($this->signedLoad(['shop' => 'victim.myshopify.com'])));
    }

    public function testIdTokenWithConflictingHeaderShopIsRejected(): void
    {
        $request = $this->makeRequest($this->signedLoad(), 'GET', ['HTTP_X_SHOP_DOMAIN' => 'victim.myshopify.com']);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(403);
        $this->runMiddleware(VerifyShopify::class, $request);
    }

    public function testPostWithIdTokenInQueryIsRejected(): void
    {
        $request = $this->makeRequest($this->signedLoad(), 'POST');

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(400);
        $this->runMiddleware(VerifyShopify::class, $request);
    }

    public function testMethodOverrideDoesNotReadIdTokenFromBody(): void
    {
        Request::enableHttpMethodParameterOverride();
        $request = Request::create('/orders', 'POST', ['_method' => 'GET', 'id_token' => $this->buildToken()]);

        [$called] = $this->runMiddleware(VerifyShopify::class, $request);

        $this->assertFalse($called);
        $this->assertGuest();
    }

    public function testArrayIdTokenIsRejected(): void
    {
        // Shopify signs array values as key=["a", "b"]
        $timestamp = (string) $this->now->getTimestamp();
        $message = 'id_token=["x"]&shop=shop-name.myshopify.com&timestamp='.$timestamp;
        $query = [
            'id_token' => ['x'],
            'shop' => 'shop-name.myshopify.com',
            'timestamp' => $timestamp,
            'hmac' => hash_hmac('sha256', $message, $this->app['config']->get('shopify-app.api_secret')),
        ];

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(400);
        $this->runMiddleware(VerifyShopify::class, $this->makeRequest($query));
    }

    public function testEmptyIdTokenIsTreatedAsMissing(): void
    {
        [$called, $response] = $this->runMiddleware(VerifyShopify::class, $this->makeRequest($this->signedLoad(['id_token' => ''])));

        $this->assertFalse($called);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('/authenticate/token', $response->getTargetUrl());
    }

    public function testTokenTakesPrecedenceOverIdToken(): void
    {
        $expired = $this->buildToken(['exp' => $this->now->getTimestamp() - 120]);
        $query = $this->signedLoad(['token' => $expired]);

        [$called] = $this->runMiddleware(VerifyShopify::class, $this->makeRequest($query));

        $this->assertFalse($called);
        $this->assertGuest();
    }

    public function testBearerTakesPrecedenceOverIdToken(): void
    {
        $expired = $this->buildToken(['exp' => $this->now->getTimestamp() - 120]);
        $request = $this->makeRequest($this->signedLoad(), 'GET', ['HTTP_AUTHORIZATION' => "Bearer {$expired}"]);

        [$called] = $this->runMiddleware(VerifyShopify::class, $request);

        $this->assertFalse($called);
        $this->assertGuest();
    }
}
