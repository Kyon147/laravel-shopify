<?php

namespace Osiset\ShopifyApp\Actions;

use Exception;
use Osiset\ShopifyApp\Contracts\Commands\Shop as IShopCommand;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Objects\Enums\AuthMode;
use Osiset\ShopifyApp\Objects\Values\AccessToken;
use Osiset\ShopifyApp\Objects\Values\NullAccessToken;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;
use Osiset\ShopifyApp\Util;

/**
 * Install steps for a shop.
 */
class InstallShop
{
    /**
     * Querier for shops.
     *
     * @var IShopQuery
     */
    protected $shopQuery;

    /**
     * Commander for shops.
     *
     * @var IShopCommand
     */
    protected $shopCommand;

    /**
     * Setup.
     *
     * @param IShopQuery  $shopQuery   The querier for the shop.
     *
     * @return void
     */
    public function __construct(
        IShopQuery $shopQuery,
        IShopCommand $shopCommand
    ) {
        $this->shopQuery = $shopQuery;
        $this->shopCommand = $shopCommand;
    }

    /**
     * Execution.
     *
     * @param ShopDomain  $shopDomain The shop ID.
     * @param string|null $code       The code from Shopify.
     *
     * @return array
     */
    public function __invoke(ShopDomain $shopDomain, ?string $code): array
    {
        // Get the shop
        $shop = $this->shopQuery->getByDomain($shopDomain, [], true);
        if ($shop === null) {
            // Shop does not exist, make them and re-get
            $this->shopCommand->make($shopDomain, NullAccessToken::fromNative(null));
            $shop = $this->shopQuery->getByDomain($shopDomain);
        }

        // Access/grant mode
        $apiHelper = $shop->apiHelper();
        $grantMode = $shop->hasOfflineAccess() ?
            AuthMode::fromNative(Util::getShopifyConfig('api_grant_mode', $shop)) :
            AuthMode::OFFLINE();

        // If there's no code
        if (empty($code)) {
            return [
                'completed' => false,
                'url' => $apiHelper->buildAuthUrl($grantMode, Util::getShopifyConfig('api_scopes', $shop)),
                'shop_id' => $shop->getId(),
            ];
        }

        try {
            // if the store has been deleted, restore the store to set the access token
            if ($shop->trashed()) {
                $shop->restore();
            }

            // Get the data and set the access token
            $data = $apiHelper->getAccessData($code);
            $this->shopCommand->setAccessToken($shop->getId(), AccessToken::fromNative($data['access_token']));

            return [
                'completed' => true,
                'url' => null,
                'shop_id' => $shop->getId(),
            ];
        } catch (Exception $e) {
            // Just return the default setting
            return [
                'completed' => false,
                'url' => null,
                'shop_id' => null,
            ];
        }
    }
}
