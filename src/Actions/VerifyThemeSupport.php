<?php

declare(strict_types=1);

namespace Osiset\ShopifyApp\Actions;

use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Contracts\ShopModel;
use Osiset\ShopifyApp\Objects\Enums\ThemeSupportLevel;
use Osiset\ShopifyApp\Objects\Values\ShopId;

final class VerifyThemeSupport
{
    private const ASSET_FILE_NAMES = ['templates/product.json', 'templates/collection.json', 'templates/index.json'];

    private const MAIN_ROLE = 'main';

    public function __construct(
        private IShopQuery $shopQuery,
        private FetchMainTheme $fetchMainTheme,
        private FetchThemeAssets $fetchThemeAssets,
    ) {
    }

    public function __invoke(ShopId $shopId): int
    {
        $shop = $this->shopQuery->getById($shopId);

        /** @var array{id: string, name: string} */
        $mainTheme = $this->fetchMainTheme->handle($shop);

        if (isset($mainTheme['id'])) {
            /** @var array<int, array{filename: string, content: string}> */
            $assets = $this->fetchThemeAssets->handle(
                shop: $shop,
                mainThemeId: $mainTheme['id'],
                filenames: self::ASSET_FILE_NAMES
            );
            $templateMainSections = $this->mainSections(
                shop: $shop,
                mainTheme: $mainTheme,
                assets: $assets
            );
            $sectionsWithAppBlock = $this->sectionsWithAppBlock($templateMainSections);

            $hasTemplates = count($assets) > 0;
            $allTemplatesHasRightType = count($assets) === count($sectionsWithAppBlock);
            $hasTemplatesCountWithRightType = count($sectionsWithAppBlock) > 0;

            return match (true) {
                $hasTemplates && $allTemplatesHasRightType => ThemeSupportLevel::FULL,
                $hasTemplatesCountWithRightType => ThemeSupportLevel::PARTIAL,
                default => ThemeSupportLevel::UNSUPPORTED
            };
        }

        return ThemeSupportLevel::UNSUPPORTED;
    }

    /**
     * @param  array{id: string}  $mainTheme
     * @param  array{content: string}  $assets
     *
     * @return array<int, array{filename: string, content: string}>
     */
    private function mainSections(ShopModel $shop, array $mainTheme, array $assets): array
    {
        $filenamesForMainSections = array_filter(
            array_map(function ($asset) {
                $content = $asset['content'];

                if (! $this->json_validate($content)) {
                    $content = preg_replace("#(/\*([^*]|[\r\n]|(\*+([^*/]|[\r\n])))*\*+/)|([\s\t]//.*)|(^//.*)#", '', $content);
                }

                $assetContent = json_decode($content, true);


                $mainAsset = array_filter($assetContent['sections'], function ($value, $key) {
                    return $key == self::MAIN_ROLE || str_starts_with($value['type'], self::MAIN_ROLE);
                }, ARRAY_FILTER_USE_BOTH);

                if ($mainAsset) {
                    return 'sections/'.end($mainAsset)['type'].'.liquid';
                }
            }, $assets)
        );

        return $this->fetchThemeAssets->handle(
            shop: $shop,
            mainThemeId: $mainTheme['id'],
            filenames: $filenamesForMainSections
        );
    }

    private function sectionsWithAppBlock(array $templateMainSections): array
    {
        return array_filter(array_map(function ($file) {
            $acceptsAppBlock = false;

            preg_match('/\{\%-?\s+schema\s+-?\%\}([\s\S]*?)\{\%-?\s+endschema\s+-?\%\}/m', $file['content'], $matches);
            $schema = json_decode($matches[1] ?? '{}', true);

            if ($schema && isset($schema['blocks'])) {
                $acceptsAppBlock = in_array('@app', array_column($schema['blocks'], 'type'));
            }

            return $acceptsAppBlock ? $file : null;
        }, $templateMainSections));
    }


    private function json_validate(string $string): bool
    {
        json_decode($string);

        return json_last_error() === JSON_ERROR_NONE;
    }
}
