<?php

namespace Osiset\ShopifyApp\Test;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Osiset\ShopifyApp\Http\Middleware\IframeProtection;

class IframeProtectionMiddlewareGroupsTest extends TestCase
{
    public function testAddsToWebGroupByDefault(): void
    {
        $this->assertContains(IframeProtection::class, $this->middlewareGroup('web'));
    }

    #[DefineEnvironment('useShopifyGroup')]
    public function testAddsToConfiguredGroupsOnly(): void
    {
        $this->assertContains(IframeProtection::class, $this->middlewareGroup('shopify'));
        $this->assertNotContains(IframeProtection::class, $this->middlewareGroup('web'));
    }

    #[DefineEnvironment('useNoGroups')]
    public function testAddsToNoGroupWhenEmpty(): void
    {
        $this->assertNotContains(IframeProtection::class, $this->middlewareGroup('web'));
    }

    #[DefineEnvironment('removeSetting')]
    public function testFallsBackToWebGroupWhenSettingIsMissing(): void
    {
        $this->assertContains(IframeProtection::class, $this->middlewareGroup('web'));
    }

    protected function useShopifyGroup(Application $app): void
    {
        $app['config']->set('shopify-app.iframe_protection_middleware_groups', ['shopify']);
    }

    protected function useNoGroups(Application $app): void
    {
        $app['config']->set('shopify-app.iframe_protection_middleware_groups', []);
    }

    protected function removeSetting(Application $app): void
    {
        $config = $app['config']->get('shopify-app');
        unset($config['iframe_protection_middleware_groups']);
        $app['config']->set('shopify-app', $config);
    }

    private function middlewareGroup(string $name): array
    {
        return $this->app['router']->getMiddlewareGroups()[$name] ?? [];
    }
}
