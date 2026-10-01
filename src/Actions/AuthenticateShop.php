<?php

namespace Osiset\ShopifyApp\Actions;

use Illuminate\Http\Request;
use Osiset\ShopifyApp\Contracts\ApiHelper as IApiHelper;
use Osiset\ShopifyApp\Messaging\Events\AppInstalledEvent;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;

class AuthenticateShop
{
    public function __construct(
        protected IApiHelper $apiHelper,
        protected InstallShop $installShopAction,
        protected DispatchWebhooks $dispatchWebhooksAction,
        protected AfterAuthorize $afterAuthorizeAction
    ) {
    }

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
            $this->apiHelper->make();

            if (! $this->apiHelper->verifyRequest($request->all())) {
                return [$result, null];
            }
        }

        call_user_func($this->dispatchWebhooksAction, $result['shop_id'], false);
        call_user_func($this->afterAuthorizeAction, $result['shop_id']);

        event(new AppInstalledEvent($result['shop_id']));

        return [$result, true];
    }
}
