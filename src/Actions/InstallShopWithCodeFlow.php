<?php

namespace Osiset\ShopifyApp\Actions;

use Exception;
use Gnikyt\BasicShopifyAPI\Session;
use Illuminate\Support\Carbon;
use Osiset\ShopifyApp\Contracts\ApiHelper as IApiHelper;
use Osiset\ShopifyApp\Contracts\Commands\Shop as IShopCommand;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Contracts\ShopModel as IShopModel;
use Osiset\ShopifyApp\Objects\Enums\AuthMode;
use Osiset\ShopifyApp\Objects\Values\AccessToken;
use Osiset\ShopifyApp\Objects\Values\NullAccessToken;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;
use Osiset\ShopifyApp\Util;

class InstallShopWithCodeFlow
{
    public function __construct(
        protected IShopQuery $shopQuery,
        protected IShopCommand $shopCommand,
        protected IApiHelper $apiHelper
    ) {
    }

    public function handle(ShopDomain $shopDomain, ?string $code): array
    {
        $shop = $this->shopQuery->getByDomain($shopDomain, [], true);

        if ($shop === null) {
            $this->shopCommand->make($shopDomain, NullAccessToken::fromNative(null));
            $shop = $this->shopQuery->getByDomain($shopDomain);
        }

        $apiHelper = $this->apiHelper->make(new Session(
            $shop->getDomain()->toNative(),
            $shop->getAccessToken()->toNative()
        ));
        $grantMode = $shop->hasOfflineAccess()
            ? AuthMode::fromNative(Util::getShopifyConfig('api_grant_mode', $shop))
            : AuthMode::OFFLINE();

        if (empty($code)) {
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

            $data = $apiHelper->getAccessData($code, $grantMode);
            $this->persistShopifyOAuthTokens($shop, $data, $grantMode);

            return [
                'completed' => true,
                'url' => null,
                'shop_id' => $shop->getId(),
            ];
        } catch (Exception $e) {
            return [
                'completed' => false,
                'url' => null,
                'shop_id' => null,
            ];
        }
    }

    /**
     * Persist OAuth tokens and optional expiring-offline metadata.
     *
     * @param IShopModel $shop
     * @param mixed      $data
     * @param AuthMode   $grantMode
     *
     * @return void
     */
    protected function persistShopifyOAuthTokens(IShopModel $shop, $data, AuthMode $grantMode): void
    {
        $expiringEnabled = Util::getShopifyConfig('expiring_offline_tokens', $shop);
        $isOfflineGrant = $grantMode->isSame(AuthMode::OFFLINE());

        if ($expiringEnabled && $isOfflineGrant && isset($data['refresh_token'])) {
            $this->shopCommand->setAccessToken(
                $shop->getId(),
                AccessToken::fromNative($data['access_token']),
                $data['refresh_token'],
                Carbon::now()->addSeconds((int) $data['expires_in']),
                Carbon::now()->addSeconds((int) $data['refresh_token_expires_in'])
            );

            return;
        }

        $this->shopCommand->setAccessToken(
            $shop->getId(),
            AccessToken::fromNative($data['access_token'])
        );
    }
}
