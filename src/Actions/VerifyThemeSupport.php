<?php

declare(strict_types=1);

namespace Osiset\ShopifyApp\Actions;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Contracts\ShopModel;
use Osiset\ShopifyApp\Objects\Enums\ThemeSupportLevel;
use Osiset\ShopifyApp\Objects\Values\ShopId;
use Osiset\ShopifyApp\Util;

final class VerifyThemeSupport
{
    private const ASSET_FILE_NAMES = ['templates/product.json', 'templates/collection.json', 'templates/index.json'];

    private const MAIN_ROLE = 'main';

    private string $cacheInterval;

    private int $cacheDuration;

    public function __construct(
        private IShopQuery $shopQuery,
        private FetchMainTheme $fetchMainTheme,
        private FetchThemeAssets $fetchThemeAssets,
    ) {
        $this->cacheInterval = (string) Str::of(Util::getShopifyConfig('theme_support.cache_interval'))
            ->plural()
            ->ucfirst()
            ->start('add');

        $this->cacheDuration = (int) Util::getShopifyConfig('theme_support.cache_duration');
    }

    public function __invoke(ShopId $shopId): int
    {
        $shop = $this->shopQuery->getById($shopId);

        /** @var array{id: string, name: string} */
        $mainTheme = Cache::remember(
            "mainTheme.{$shop->getId()->toNative()}",
            now()->{$this->cacheInterval}($this->cacheDuration),
            fn () => $this->fetchMainTheme->handle($shop)
        );

        if (isset($mainTheme['id'])) {
            /** @var array<int, array{filename: string, content: string}> */
            $assets = Cache::remember(
                "assets.{$mainTheme['id']}.{$shop->getId()->toNative()}",
                now()->{$this->cacheInterval}($this->cacheDuration),
                fn () => $this->fetchThemeAssets->handle(
                    shop: $shop,
                    mainThemeId: $mainTheme['id'],
                    filenames: self::ASSET_FILE_NAMES
                )
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
     * @template T
     * @template Z
     *
     * @param  Z  $mainTheme
     * @param  T  $assets
     *
     * @return T
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

        return Cache::remember(
            "mainSections.{$mainTheme['id']}.".sha1(implode('|', $filenamesForMainSections)),
            now()->{$this->cacheInterval}($this->cacheDuration),
            fn () => $this->fetchThemeAssets->handle(
                shop: $shop,
                mainThemeId: $mainTheme['id'],
                filenames: [...$filenamesForMainSections]
            )
        );
    }

    /**
     * @template T
     *
     * @param  T  $templateMainSections
     *
     * @return T
     */
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
