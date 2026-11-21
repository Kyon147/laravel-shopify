<?php

namespace Osiset\ShopifyApp\Test\Actions;

use Osiset\ShopifyApp\Actions\VerifyThemeSupport;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Objects\Enums\ThemeSupportLevel;
use Osiset\ShopifyApp\Objects\Values\ShopId;
use Osiset\ShopifyApp\Services\ThemeHelper;
use Osiset\ShopifyApp\Test\TestCase;

class VerifyThemeSupportTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
    }

    public function testStoreWithUndefinedMainTheme(): void
    {
        $shop = factory($this->model)->create();
        $action = $this->app->make(VerifyThemeSupport::class);

        $result = call_user_func(
            $action,
            ShopId::fromNative($shop->id)
        );

        $this->assertNotNull($result);
        $this->assertEquals(ThemeSupportLevel::Unsupported, $result);
    }

    public function testStoreWithFullExtensionSupport(): void
    {
        $shop = factory($this->model)->create();
        $themeHelperStub = $this->createThemeHelperStub(ThemeSupportLevel::Full);
        $action = new VerifyThemeSupport($this->app->make(IShopQuery::class), $themeHelperStub);

        $result = call_user_func(
            $action,
            ShopId::fromNative($shop->id)
        );

        $this->assertNotNull($result);
        $this->assertEquals(ThemeSupportLevel::Full, $result);
    }

    public function testStoreWithPartialExtensionSupport(): void
    {
        $shop = factory($this->model)->create();
        $themeHelperStub = $this->createThemeHelperStub(ThemeSupportLevel::Partial);
        $action = new VerifyThemeSupport($this->app->make(IShopQuery::class), $themeHelperStub);

        $result = call_user_func(
            $action,
            ShopId::fromNative($shop->id)
        );

        $this->assertNotNull($result);
        $this->assertEquals(ThemeSupportLevel::Partial, $result);
    }

    public function testStoreWithoutExtensionSupport(): void
    {
        $shop = factory($this->model)->create();
        $themeHelperStub = $this->createThemeHelperStub(ThemeSupportLevel::Unsupported);
        $action = new VerifyThemeSupport($this->app->make(IShopQuery::class), $themeHelperStub);

        $result = call_user_func(
            $action,
            ShopId::fromNative($shop->id)
        );

        $this->assertNotNull($result);
        $this->assertEquals(ThemeSupportLevel::Unsupported, $result);
    }

    /**
     * Create ThemeHelper stub
     *
     * @return ThemeHelper
     */
    protected function createThemeHelperStub(ThemeSupportLevel $level): ThemeHelper
    {
        $themeHelperStub = $this->createStub(ThemeHelper::class);

        $defaultThemeResponse = [];

        if ($level === ThemeSupportLevel::Full) {
            $defaultThemeResponse = [0, 1, 2, 3];
        }

        $themeHelperStub->method('themeIsReady')->willReturn(true);
        $themeHelperStub->method('templateJSONFiles')->willReturn($defaultThemeResponse);
        $themeHelperStub->method('mainSections')->willReturn($defaultThemeResponse);
        $themeHelperStub->method('sectionsWithAppBlock')->willReturn(
            $level === ThemeSupportLevel::Partial
            ? array_merge($defaultThemeResponse, [random_int(1, 99)])
            : $defaultThemeResponse
        );

        return $themeHelperStub;
    }
}
