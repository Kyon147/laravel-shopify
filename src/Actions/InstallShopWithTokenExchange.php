<?php

namespace Osiset\ShopifyApp\Actions;

use Exception;
use Illuminate\Support\Carbon;
use Osiset\ShopifyApp\Contracts\Commands\Shop as IShopCommand;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Contracts\ShopModel as IShopModel;
use Osiset\ShopifyApp\Objects\Values\AccessToken;
use Osiset\ShopifyApp\Objects\Values\NullAccessToken;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;
use Osiset\ShopifyApp\Util;

class InstallShopWithTokenExchange
{
    public function __construct(
        protected IShopQuery $shopQuery,
        protected IShopCommand $shopCommand
    ) {
    }

    public function handle(ShopDomain $shopDomain, ?string $idToken = null): array
    {
        $shop = $this->shopQuery->getByDomain($shopDomain, [], true);

        if ($shop === null) {
            $this->shopCommand->make($shopDomain, NullAccessToken::fromNative(null));
            $shop = $this->shopQuery->getByDomain($shopDomain);
        }

        if ($idToken === null && ! $shop->hasOfflineAccess()) {
            return [
                'completed' => false,
                'url' => null,
                'shop_id' => $shop->getId(),
            ];
        }

        try {
            if ($shop->trashed()) {
                $shop->restore();
            }

            if (! $shop->hasOfflineAccess()) {
                $data = $shop->apiHelper()->performOfflineTokenExchange($idToken);
                $this->persistShopifyOAuthTokens($shop, $data);
            }

            return [
                'completed' => true,
                'url' => null,
                'shop_id' => $shop->getId(),
            ];
        } catch (Exception) {
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
     *
     * @return void
     */
    protected function persistShopifyOAuthTokens(IShopModel $shop, $data): void
    {
        $expiringEnabled = Util::getShopifyConfig('expiring_offline_tokens', $shop);

        if ($expiringEnabled && isset($data['refresh_token'])) {
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
