<?php

namespace Osiset\ShopifyApp\Test\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Osiset\ShopifyApp\Exceptions\HttpException;
use Osiset\ShopifyApp\Http\Middleware\VerifyShopify;
use Osiset\ShopifyApp\Test\TestCase;

class VerifyShopifyTenantTest extends TestCase
{
    /**
     * @dataProvider conflictingStoreProvider
     */
    public function testRejectsConflictingStoreIdentifiers(array $inputs): void
    {
        factory($this->model)->create(['name' => 'shop-name.myshopify.com']);
        factory($this->model)->create(['name' => 'victim.myshopify.com']);

        $request = Request::create('/api/action', 'POST');
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Authorization', 'Bearer '.$this->buildToken());
        foreach ($inputs as $source => $values) {
            if ($source === 'route') {
                $route = new Route('POST', '/api/{shop}', fn () => null);
                $route->bind(Request::create('/api/'.$values['shop'], 'POST'));
                $request->setRouteResolver(fn () => $route);
            } else {
                if ($source === 'json') {
                    $request->headers->set('Content-Type', 'application/json');
                    $request->json()->replace($values);
                } else {
                    $request->{$source}->add($values);
                }
            }
        }

        $dispatched = false;

        try {
            $this->runMiddleware(VerifyShopify::class, $request, function () use (&$dispatched) {
                $dispatched = true;
            });
            $this->fail('A conflicting tenant must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Request shop does not match session token.', $e->getMessage());
        }

        $this->assertFalse($dispatched);
        $this->assertGuest();
    }

    public static function conflictingStoreProvider(): array
    {
        $victim = 'victim.myshopify.com';
        $own = 'shop-name.myshopify.com';

        return [
            'query shop' => [['query' => ['shop' => $victim]]],
            'query alias' => [['query' => ['shopDomain' => $victim]]],
            'form shop' => [['request' => ['shop' => $victim]]],
            'JSON shop' => [['json' => ['shop' => $victim]]],
            'JSON alias' => [['json' => ['shopDomain' => $victim]]],
            'header shop' => [['headers' => ['X-Shop-Domain' => $victim]]],
            'referer shop' => [['headers' => ['Referer' => 'https://localhost/?shop='.$victim]]],
            'referer alias' => [['headers' => ['Referer' => 'https://localhost/?shopDomain='.$victim]]],
            'route shop' => [['route' => ['shop' => $victim]]],
            'attribute shop' => [['attributes' => ['shop' => $victim]]],
            'hidden query behind form' => [['query' => ['shop' => $victim], 'request' => ['shop' => $own]]],
            'hidden alias' => [['query' => ['shop' => $own, 'shopDomain' => $victim]]],
            'hidden header' => [['query' => ['shop' => $own], 'headers' => ['X-Shop-Domain' => $victim]]],
            'invalid domain' => [['query' => ['shop' => 'invalid.example.com']]],
            'array domain' => [['query' => ['shop' => [$victim]]]],
            'null domain' => [['query' => ['shop' => null]]],
        ];
    }

    /**
     * @dataProvider authenticatedRequestProvider
     */
    public function testVerifiedStoreIsAvailableToControllers(string $frontend, string $method, string $transport): void
    {
        $shop = factory($this->model)->create(['name' => 'shop-name.myshopify.com']);
        config(['shopify-app.frontend_type' => $frontend]);
        $request = Request::create('/action', $method);
        if ($transport === 'bearer') {
            // Bearer tokens must also work without AJAX/JSON headers.
            $request->headers->set('Authorization', 'Bearer '.$this->buildToken());
        } else {
            $request->merge(['token' => $this->buildToken(), 'shopDomain' => 'SHOP-NAME']);
        }

        [$called] = $this->runMiddleware(VerifyShopify::class, $request, function (Request $request) use ($shop) {
            $this->assertSame($shop->name, $request->input('shop'));
            $this->assertSame($shop->name, $request->query('shop'));
            $this->assertSame($shop->name, $request->get('shop'));
            $this->assertSame($shop->name, Auth::user()->name);
            if ($request->has('shopDomain')) {
                $this->assertSame($shop->name, $request->input('shopDomain'));
            }
        });

        $this->assertTrue($called);
    }

    public static function authenticatedRequestProvider(): array
    {
        return [
            'MPA browser bearer' => ['MPA', 'GET', 'bearer'],
            'SPA browser bearer' => ['SPA', 'GET', 'bearer'],
            'MPA query token' => ['MPA', 'GET', 'input'],
            'SPA form token' => ['SPA', 'POST', 'input'],
        ];
    }

    /**
     * @dataProvider unauthenticatedRequestProvider
     */
    public function testRequestCannotAuthenticateUsingOnlyAStoreName(string $frontend, string $uri): void
    {
        factory($this->model)->create(['name' => 'victim.myshopify.com']);
        config(['shopify-app.frontend_type' => $frontend]);
        $request = Request::create($uri, 'GET');
        $request->query->set('shop', 'victim.myshopify.com');

        [$called, $response] = $this->runMiddleware(VerifyShopify::class, $request);

        $this->assertFalse($called);
        $this->assertStringContainsString('/authenticate/token', $response->getTargetUrl());
        $this->assertGuest();
    }

    public static function unauthenticatedRequestProvider(): array
    {
        return [
            'MPA billing' => ['MPA', '/billing/1'],
            'SPA billing' => ['SPA', '/billing/1'],
            'SPA browser action' => ['SPA', '/action'],
            'billing substring in path' => ['MPA', '/actions/billing/export'],
            'authenticate substring in path' => ['MPA', '/actions/authenticate/export'],
            'billing substring in query' => ['MPA', '/action?next=/billing'],
            'authenticate substring in query' => ['MPA', '/action?next=/authenticate'],
        ];
    }

    public function testBrowserPostWithoutTokenDoesNotRedirectOrRunAction(): void
    {
        factory($this->model)->create(['name' => 'shop-name.myshopify.com']);
        $request = Request::create('/billing/usage-charge', 'POST', ['shop' => 'shop-name.myshopify.com']);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(400);
        $this->runMiddleware(VerifyShopify::class, $request);
    }

    public function testMalformedTokenInputReceivesAnAuthenticationError(): void
    {
        $request = Request::create('/api/action', 'POST', ['token' => ['invalid']]);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(400);
        $this->runMiddleware(VerifyShopify::class, $request);
    }

    public function testDeletedStoreCannotAuthenticateWithAValidToken(): void
    {
        $shop = factory($this->model)->create(['name' => 'shop-name.myshopify.com']);
        $shop->delete();
        $request = Request::create('/api/action');
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Authorization', 'Bearer '.$this->buildToken());

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(403);
        $this->runMiddleware(VerifyShopify::class, $request);
    }
}
