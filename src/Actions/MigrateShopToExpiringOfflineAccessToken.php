<?php

namespace Osiset\ShopifyApp\Actions;

use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Osiset\ShopifyApp\Contracts\ApiHelper as IApiHelper;
use Osiset\ShopifyApp\Contracts\Commands\Shop as IShopCommand;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Contracts\ShopModel as IShopModel;
use Osiset\ShopifyApp\Objects\Values\AccessToken;
use Osiset\ShopifyApp\Objects\Values\ShopId;
use Osiset\ShopifyApp\Util;

/**
 * Optionally migrate a shop from a non-expiring offline token to Shopify expiring offline tokens.
 *
 * @link https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens/offline-access-tokens#step-4-migrate-existing-tokens
 */
class MigrateShopToExpiringOfflineAccessToken
{
    public const REASON_ALREADY_EXPIRING = 'already_expiring';

    public const REASON_FEATURE_DISABLED = 'expiring_offline_tokens_disabled';

    public const REASON_NO_OFFLINE_TOKEN = 'no_offline_access_token';

    public const REASON_SHOP_NOT_FOUND = 'shop_not_found';

    public function __construct(
        protected IShopQuery $shopQuery,
        protected IShopCommand $shopCommand,
        protected IApiHelper $apiHelper
    ) {
    }

    /**
     * @param IShopModel|ShopId $shopOrId
     *
     * @return array{migrated: bool, skipped: bool, reason: string|null, shop_id: int|null, error: string|null}
     */
    public function __invoke(IShopModel|ShopId $shopOrId): array
    {
        $shop = $shopOrId instanceof ShopId
            ? $this->shopQuery->getById($shopOrId)
            : $shopOrId;

        if ($shop === null) {
            return $this->result(skipped: true, reason: self::REASON_SHOP_NOT_FOUND);
        }

        if (! Util::getShopifyConfig('expiring_offline_tokens', $shop)) {
            return $this->result(skipped: true, reason: self::REASON_FEATURE_DISABLED, shopId: $shop->getId()->toNative());
        }

        if ($shop->hasExpiringOfflineAccess()) {
            return $this->result(skipped: true, reason: self::REASON_ALREADY_EXPIRING, shopId: $shop->getId()->toNative());
        }

        if (! $shop->hasOfflineAccess()) {
            return $this->result(skipped: true, reason: self::REASON_NO_OFFLINE_TOKEN, shopId: $shop->getId()->toNative());
        }

        $result = [
            'migrated' => false,
            'skipped' => false,
            'reason' => null,
            'shop_id' => $shop->getId()->toNative(),
            'error' => null,
        ];

        Cache::lock('shopify-offline-migrate:'.$shop->getId()->toNative(), 30)->block(10, function () use ($shop, &$result) {
            $shop->refresh();

            if ($shop->hasExpiringOfflineAccess()) {
                $result = $this->result(
                    skipped: true,
                    reason: self::REASON_ALREADY_EXPIRING,
                    shopId: $shop->getId()->toNative()
                );

                return;
            }

            if (! $shop->hasOfflineAccess()) {
                $result = $this->result(
                    skipped: true,
                    reason: self::REASON_NO_OFFLINE_TOKEN,
                    shopId: $shop->getId()->toNative()
                );

                return;
            }

            try {
                $data = $this->apiHelper->exchangeNonExpiringOfflineTokenForExpiring(
                    $shop->getDomain()->toNative(),
                    $shop->getAccessToken()->toNative()
                );

                if (! isset($data['refresh_token'], $data['access_token'], $data['expires_in'], $data['refresh_token_expires_in'])) {
                    $result = $this->result(
                        shopId: $shop->getId()->toNative(),
                        error: 'Invalid token exchange response from Shopify.'
                    );

                    return;
                }

                $this->shopCommand->setAccessToken(
                    $shop->getId(),
                    AccessToken::fromNative($data['access_token']),
                    $data['refresh_token'],
                    Carbon::now()->addSeconds((int) $data['expires_in']),
                    Carbon::now()->addSeconds((int) $data['refresh_token_expires_in'])
                );

                $result = $this->result(migrated: true, shopId: $shop->getId()->toNative());
            } catch (Exception $e) {
                $result = $this->result(shopId: $shop->getId()->toNative(), error: $e->getMessage());
            }
        });

        return $result;
    }

    /**
     * @return array{migrated: bool, skipped: bool, reason: string|null, shop_id: int|null, error: string|null}
     */
    protected function result(
        bool $migrated = false,
        bool $skipped = false,
        ?string $reason = null,
        ?int $shopId = null,
        ?string $error = null
    ): array {
        return [
            'migrated' => $migrated,
            'skipped' => $skipped,
            'reason' => $reason,
            'shop_id' => $shopId,
            'error' => $error,
        ];
    }
}
