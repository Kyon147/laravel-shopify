<?php

namespace Osiset\ShopifyApp\Actions;

use Illuminate\Http\Request;
use Osiset\ShopifyApp\Contracts\ApiHelper as IApiHelper;
use Osiset\ShopifyApp\Messaging\Events\AppInstalledEvent;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;
use Throwable;

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

        // On the sync queue the webhook installer runs right here. Its failure must not stop
        // the install after the token is saved: nothing would run the steps below again.
        try {
            call_user_func($this->dispatchWebhooksAction, $result['shop_id'], false);
        } catch (Throwable $e) {
            report($e);
        }

        call_user_func($this->afterAuthorizeAction, $result['shop_id']);

        event(new AppInstalledEvent($result['shop_id']));

        return [$result, true];
    }
}
