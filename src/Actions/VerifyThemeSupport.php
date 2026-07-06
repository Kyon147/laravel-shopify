<?php

namespace Osiset\ShopifyApp\Actions;

use Illuminate\Support\Facades\Log;
use Osiset\ShopifyApp\Contracts\Commands\Shop as IShopCommand;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Objects\Enums\ThemeSupportLevel;
use Osiset\ShopifyApp\Objects\Values\ShopId;
use Osiset\ShopifyApp\Objects\Values\ThemeSupportLevel as ThemeSupportLevelValue;
use Osiset\ShopifyApp\Services\ThemeHelper;

class VerifyThemeSupport
{
    public function __construct(
        protected IShopQuery $shopQuery,
        protected IShopCommand $shopCommand,
        protected ThemeHelper $themeHelper
    ) {
    }

    public function handle(ShopId $shopId): int
    {
        $shop = $this->shopQuery->getById($shopId);
        $themeSupportLevel = ThemeSupportLevel::UNSUPPORTED;

        try {
            $this->themeHelper->extractStoreMainTheme($shopId);

            if ($this->themeHelper->themeIsReady()) {
                $templateJSONFiles = $this->themeHelper->templateJSONFiles();
                $templateMainSections = $this->themeHelper->mainSections($templateJSONFiles);
                $sectionsWithAppBlock = $this->themeHelper->sectionsWithAppBlock($templateMainSections);

                $hasTemplates = count($templateJSONFiles) > 0;
                $allTemplatesHasRightType = count($templateJSONFiles) === count($sectionsWithAppBlock);
                $templatesCountWithRightType = count($sectionsWithAppBlock) > 0;

                $themeSupportLevel = match (true) {
                    $hasTemplates && $allTemplatesHasRightType => ThemeSupportLevel::FULL,
                    $templatesCountWithRightType => ThemeSupportLevel::PARTIAL,
                    default => ThemeSupportLevel::UNSUPPORTED,
                };
            }
        } catch (\Throwable $th) {
            Log::error("Fetching theme level support: {$th->getMessage()}");
        }

        $this->shopCommand->setThemeSupportLevel($shop->getId(), ThemeSupportLevelValue::fromNative($themeSupportLevel));

        return $themeSupportLevel;
    }
}
