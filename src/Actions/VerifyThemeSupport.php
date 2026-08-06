<?php

namespace Osiset\ShopifyApp\Actions;

use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Objects\Enums\ThemeSupportLevel;
use Osiset\ShopifyApp\Objects\Values\ShopId;
use Osiset\ShopifyApp\Services\ThemeHelper;

class VerifyThemeSupport
{
    public function __construct(
        protected IShopQuery $shopQuery,
        protected ThemeHelper $themeHelper
    ) {
    }

    public function __invoke(ShopId $shopId): int
    {
        $this->themeHelper->extractStoreMainTheme($shopId);

        if ($this->themeHelper->themeIsReady()) {
            $templateJSONFiles = $this->themeHelper->templateJSONFiles();
            $templateMainSections = $this->themeHelper->mainSections($templateJSONFiles);
            $sectionsWithAppBlock = $this->themeHelper->sectionsWithAppBlock($templateMainSections);

            $hasTemplates = count($templateJSONFiles) > 0;
            $allTemplatesHasRightType = count($templateJSONFiles) === count($sectionsWithAppBlock);
            $templatesСountWithRightType = count($sectionsWithAppBlock);

            switch (true) {
                case $hasTemplates && $allTemplatesHasRightType:
                    return ThemeSupportLevel::FULL;

                case $templatesСountWithRightType:
                    return ThemeSupportLevel::PARTIAL;

                default:
                    return ThemeSupportLevel::UNSUPPORTED;
            }
        }

        return ThemeSupportLevel::UNSUPPORTED;
    }
}
