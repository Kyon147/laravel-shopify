<?php

namespace Osiset\ShopifyApp\Test\Http\Middleware;

use Illuminate\Auth\AuthManager;
use Osiset\ShopifyApp\Test\TestCase;
use Osiset\ShopifyApp\Util;
use Osiset\ShopifyApp\Http\Middleware\VerifyScopes as VerifyScopesMiddleware;

class VerifyScopesTest extends TestCase
{
    /**
     * @var AuthManager
     */
    protected $auth;

    public function setUp(): void
    {
        parent::setUp();
        $this->auth = $this->app->make(AuthManager::class);
    }
    public function testMissingScopes(): void
    {
        $shop = factory($this->model)->create();
        $this->auth->login($shop);
        // Get current Scopes
        $scopes = $this->app['config']->get('shopify-app.access_scopes');
        $scopes = explode(',', $scopes);
        unset($scopes[0]);
        $newScopes = implode(',', $scopes);
        $this->app['config']->set('shopify-app.access_scopes', $newScopes);
        
        // Run the middleware
        $result = $this->runMiddleware(VerifyScopesMiddleware::class);
        $this->assertStatus(302);
     }
}
