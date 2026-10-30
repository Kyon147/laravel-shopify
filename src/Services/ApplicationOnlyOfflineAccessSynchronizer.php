<?php

namespace Osiset\ShopifyApp\Services;

use Illuminate\Support\Carbon;
use Osiset\ShopifyApp\Contracts\Commands\Shop as IShopCommand;
use Osiset\ShopifyApp\Contracts\ShopModel;
use Osiset\ShopifyApp\Data\ApplicationOnlyAccessCredentials;
use Osiset\ShopifyApp\Objects\Values\AccessToken;
use Osiset\ShopifyApp\Support\ApplicationOnlyOfflineAccess;
use RuntimeException;
use Throwable;

class ApplicationOnlyOfflineAccessSynchronizer
{
    private const REFRESH_TOKEN_PLACEHOLDER_YEARS = 10;

    public function __construct(
        private readonly ApplicationOnlyAccessTokenClient $applicationOnlyClient,
        private readonly IShopCommand $shopCommand,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function sync(ShopModel $shop, ?ApplicationOnlyAccessCredentials $credentials = null): array
    {
        $shopDomain = $shop->getDomain()->toNative();

        try {
            $tokenPayload = $this->applicationOnlyClient->requestAccessToken($shopDomain, $credentials);
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => $exception->getMessage(),
            ];
        }

        $expiresIn = (int) $tokenPayload['expires_in'];

        if ($expiresIn <= 0) {
            return [
                'ok' => false,
                'error' => 'Shopify returned an invalid expires_in for application-only access.',
            ];
        }

        $accessExpiresAt = Carbon::now()->addSeconds($expiresIn);
        $refreshPlaceholderExpiresAt = Carbon::now()->addYears(self::REFRESH_TOKEN_PLACEHOLDER_YEARS);

        $this->shopCommand->setAccessToken(
            $shop->getId(),
            AccessToken::fromNative($tokenPayload['access_token']),
            ApplicationOnlyOfflineAccess::refreshTokenSentinel(),
            $accessExpiresAt,
            $refreshPlaceholderExpiresAt,
        );

        if (method_exists($shop, 'resetApiClient')) {
            $shop->resetApiClient();
        }

        return [
            'ok' => true,
            'expires_in' => $expiresIn,
            'scope' => $tokenPayload['scope'],
            'application_only_offline_access' => true,
        ];
    }

    /**
     * @throws RuntimeException
     */
    public function syncOrFail(ShopModel $shop, ?ApplicationOnlyAccessCredentials $credentials = null): void
    {
        $result = $this->sync($shop, $credentials);

        if (($result['ok'] ?? false) !== true) {
            throw new RuntimeException(
                'Application-only offline access sync failed: '.($result['error'] ?? 'unknown error'),
            );
        }
    }
}
