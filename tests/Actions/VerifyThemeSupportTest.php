<?php

namespace Osiset\ShopifyApp\Test\Actions;

use Osiset\ShopifyApp\Actions\VerifyThemeSupport;
use Osiset\ShopifyApp\Objects\Enums\ThemeSupportLevel;
use Osiset\ShopifyApp\Test\Stubs\Api as ApiStub;
use Osiset\ShopifyApp\Test\TestCase;

class VerifyThemeSupportTest extends TestCase
{
    public function testStoreWithUndefinedMainTheme(): void
    {
        $this->fakeGraphqlApi(['empty_theme']);
        $shop = factory($this->model)->create();
        $action = $this->app->make(VerifyThemeSupport::class);

        $result = call_user_func(
            $action,
            $shop->getId()
        );

        $this->assertNotNull($result);
        $this->assertEquals(ThemeSupportLevel::UNSUPPORTED, $result);
    }

    public function testStoreWithFullExtensionSupport(): void
    {
        $this->fakeGraphqlApi(['main_theme', 'theme_with_one_asset', 'theme_with_one_section']);
        $shop = factory($this->model)->create();
        $action = $this->app->make(VerifyThemeSupport::class);

        $result = call_user_func(
            $action,
            $shop->getId()
        );

        $this->assertNotNull($result);
        $this->assertEquals(ThemeSupportLevel::FULL, $result);
    }

    public function testStoreWithPartialExtensionSupport(): void
    {
        $shop = factory($this->model)->create();
        $this->fakeGraphqlApi(['main_theme', 'theme_with_three_assets', 'theme_with_one_section']);
        $action = $this->app->make(VerifyThemeSupport::class);

        $result = call_user_func(
            $action,
            $shop->getId()
        );

        $this->assertNotNull($result);
        $this->assertEquals(ThemeSupportLevel::PARTIAL, $result);
    }

    public function testStoreWithoutExtensionSupport(): void
    {
        $shop = factory($this->model)->create();
        $this->fakeGraphqlApi(['main_theme', 'theme_with_empty_assets', 'theme_with_empty_sections']);
        $action = $this->app->make(VerifyThemeSupport::class);

        $result = call_user_func(
            $action,
            $shop->getId()
        );

        $this->assertNotNull($result);
        $this->assertEquals(ThemeSupportLevel::UNSUPPORTED, $result);
    }

    /**
     * Create ThemeHelper stub
     */
    protected function fakeGraphqlApi(array $responses): void
    {
        $this->setApiStub();

        ApiStub::stubResponses($responses);
    }
}
