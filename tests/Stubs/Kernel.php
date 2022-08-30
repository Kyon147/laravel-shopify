<?php

namespace Osiset\ShopifyApp\Test\Stubs;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Orchestra\Testbench\Http\Middleware\RedirectIfAuthenticated;
use Osiset\ShopifyApp\Http\Middleware\AuthProxy;
use Osiset\ShopifyApp\Http\Middleware\AuthWebhook;
use Osiset\ShopifyApp\Http\Middleware\Billable;
use Osiset\ShopifyApp\Http\Middleware\VerifyShopify;

class Kernel extends \Orchestra\Testbench\Foundation\Http\Kernel
{
    /**
     * The application's route middleware.
     *
     * These middleware may be assigned to groups or used individually.
     *
     * @var array
     */
    protected $routeMiddleware = [
        'auth' => Authenticate::class,
        'auth.basic' => AuthenticateWithBasicAuth::class,
        'bindings' => SubstituteBindings::class,
        'can' => Authorize::class,
        'guest' => RedirectIfAuthenticated::class,
        'throttle' => ThrottleRequests::class,

        // Added for testing
        'verify.shopify' => VerifyShopify::class,
        'auth.webhook' => AuthWebhook::class,
        'auth.proxy' => AuthProxy::class,
        'billable' => Billable::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [
        'web' => [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            // \Illuminate\Session\Middleware\AuthenticateSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],

        'api' => [
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
    ];
}
