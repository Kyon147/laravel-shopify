<?php

namespace Osiset\ShopifyApp\Actions;

use Carbon\Carbon;
use Exception;
use Osiset\ShopifyApp\Contracts\Commands\Shop as IShopCommand;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Objects\Enums\AuthMode;
use Osiset\ShopifyApp\Objects\Enums\ThemeSupportLevel as ThemeSupportLevelEnum;
use Osiset\ShopifyApp\Objects\Values\AccessToken;
use Osiset\ShopifyApp\Objects\Values\NullAccessToken;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;
use Osiset\ShopifyApp\Objects\Values\ThemeSupportLevel;
use Osiset\ShopifyApp\Util;

class InstallShop
{
    public function __construct(
        protected IShopQuery $shopQuery,
        protected IShopCommand $shopCommand,
        protected VerifyThemeSupport $verifyThemeSupport
    ) {
    }

    public function __invoke(ShopDomain $shopDomain, ?string $code = null, ?string $idToken = null): array
    {
        $shop = $this->shopQuery->getByDomain($shopDomain, [], true);

        if ($shop === null) {
            $this->shopCommand->make($shopDomain, NullAccessToken::fromNative(null));
            $shop = $this->shopQuery->getByDomain($shopDomain);
        }

        $apiHelper = $shop->apiHelper();
        $grantMode = $shop->hasOfflineAccess() ?
            AuthMode::fromNative(Util::getShopifyConfig('api_grant_mode', $shop)) :
            AuthMode::OFFLINE();

        if (empty($code) && empty($idToken)) {
            return [
                'completed' => false,
                'url' => $apiHelper->buildAuthUrl($grantMode, Util::getShopifyConfig('api_scopes', $shop)),
                'shop_id' => $shop->getId(),
            ];
        }

        try {
            if ($shop->trashed()) {
                $shop->restore();
            }

            // Get the data and set the access token
            $data = $idToken !== null ? $apiHelper->performOfflineTokenExchange($idToken) : $apiHelper->getAccessData($code);
            $this->shopCommand->setAccessToken($shop->getId(), AccessToken::fromNative($data['access_token']));

            // Persist expiring token fields when Shopify returns them.
            // As of December 2025, Shopify only issues expiring offline tokens
            // (1-hour access_token + 90-day one-time-use refresh_token).
            if (isset($data['refresh_token'])) {
                $userModel = $shop->shopModel ?? $shop;
                $updates   = [
                    'refresh_token'            => $data['refresh_token'],
                    'token_expires_at'         => isset($data['expires_in'])
                        ? Carbon::now()->addSeconds((int) $data['expires_in'])
                        : null,
                    'refresh_token_expires_at' => isset($data['refresh_token_expires_in'])
                        ? Carbon::now()->addSeconds((int) $data['refresh_token_expires_in'])
                        : null,
                ];
                $userModel->forceFill($updates)->save();
            }

            try {
                $themeSupportLevel = call_user_func($this->verifyThemeSupport, $shop->getId());
                $this->shopCommand->setThemeSupportLevel($shop->getId(), ThemeSupportLevel::fromNative($themeSupportLevel));
            } catch (Exception $e) {
                $themeSupportLevel = ThemeSupportLevelEnum::NONE;
            }


            return [
                'completed' => true,
                'url' => null,
                'shop_id' => $shop->getId(),
                'theme_support_level' => $themeSupportLevel,
            ];
        } catch (Exception $e) {
            return [
                'completed' => false,
                'url' => null,
                'shop_id' => null,
                'theme_support_level' => null,
            ];
        }
    }
}
