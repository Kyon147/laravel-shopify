<?php

namespace Osiset\ShopifyApp\Actions;

use Illuminate\Http\Request;
use Osiset\ShopifyApp\Contracts\ApiHelper as IApiHelper;
use Osiset\ShopifyApp\Messaging\Events\AppInstalledEvent;
use Osiset\ShopifyApp\Objects\Enums\AuthStrategy;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;
use Osiset\ShopifyApp\Util;

class AuthenticateShop
{
    public function __construct(
        protected IApiHelper $apiHelper,
        protected InstallShopWithCodeFlow $installShopWithCodeFlowAction,
        protected InstallShopWithTokenExchange $installShopWithTokenExchangeAction,
        protected DispatchScripts $dispatchScriptsAction,
        protected DispatchWebhooks $dispatchWebhooksAction,
        protected AfterAuthorize $afterAuthorizeAction,
        protected VerifyThemeSupport $verifyThemeSupportAction
    ) {
    }

    public function __invoke(Request $request): array
    {
        $result = match (Util::getShopifyConfig('auth_strategy')) {
            AuthStrategy::TOKEN_EXCHANGE => $this->installShopWithTokenExchangeAction->handle(
                shopDomain: ShopDomain::fromNative($request->get('shop')),
                idToken: $request->query('id_token'),
            ),
            default => $this->installShopWithCodeFlowAction->handle(
                shopDomain: ShopDomain::fromNative($request->get('shop')),
                code: $request->query('code'),
            ),
        };

        if (! $result['completed']) {
            return [$result, false];
        }

        if ($request->has('code')) {
            $this->apiHelper->make();

            if (! $this->apiHelper->verifyRequest($request->all())) {
                return [$result, null];
            }
        }

        $themeSupportLevel = $this->verifyThemeSupportAction->handle($result['shop_id']);

        if (in_array($themeSupportLevel, Util::getShopifyConfig('theme_support.unacceptable_levels'))) {
            call_user_func($this->dispatchScriptsAction, $result['shop_id'], false);
        }

        call_user_func($this->dispatchWebhooksAction, $result['shop_id'], false);
        call_user_func($this->afterAuthorizeAction, $result['shop_id']);

        event(new AppInstalledEvent($result['shop_id']));

        return [$result, true];
    }
}
