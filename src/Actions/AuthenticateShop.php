<?php

namespace Osiset\ShopifyApp\Actions;

use Illuminate\Http\Request;
use Osiset\ShopifyApp\Contracts\ApiHelper as IApiHelper;
use Osiset\ShopifyApp\Messaging\Events\AppInstalledEvent;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;
use Osiset\ShopifyApp\Util;

class AuthenticateShop
{
    public function __construct(
        protected IApiHelper $apiHelper,
        protected InstallShop $installShopAction,
        protected DispatchScripts $dispatchScriptsAction,
        protected DispatchWebhooks $dispatchWebhooksAction,
        protected AfterAuthorize $afterAuthorizeAction
    ) {
    }

    /**
     * Execution.
     *
     * Managed App Installs have an `id_token` parameter, whereas oAuth exchange has a `code` query parameter.
     *
     * @param Request $request The request object.
     *
     * @return array
     */
    public function __invoke(Request $request): array
    {
        $result = call_user_func(
            $this->installShopAction,
            ShopDomain::fromNative($request->get('shop')),
            $request->query('code'),
            $request->query('id_token'),
        );

        if (! $result['completed']) {
            return [$result, false];
        }

        if ($request->has('code')) {
            // Determine if the HMAC is correct
            $this->apiHelper->make();
            if (! $this->apiHelper->verifyRequest($request->all())) {
                // Throw exception, something is wrong
                return [$result, null];
            }
        }

        if (in_array($result['theme_support_level'], Util::getShopifyConfig('theme_support.unacceptable_levels'))) {
            call_user_func($this->dispatchScriptsAction, $result['shop_id'], false);
        }

        call_user_func($this->dispatchWebhooksAction, $result['shop_id'], false);
        call_user_func($this->afterAuthorizeAction, $result['shop_id']);

        event(new AppInstalledEvent($result['shop_id']));

        return [$result, true];
    }
}
