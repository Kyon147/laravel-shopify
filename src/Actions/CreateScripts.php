<?php

namespace Osiset\ShopifyApp\Actions;

use Gnikyt\BasicShopifyAPI\ResponseAccess;
use Osiset\ShopifyApp\Contracts\Objects\Values\ShopId as ShopIdValue;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;

class CreateScripts
{
    public function __construct(protected IShopQuery $shopQuery)
    {
    }

    public function __invoke(ShopIdValue $shopId, array $configScripts): array
    {
        $shop = $this->shopQuery->getById($shopId);
        $apiHelper = $shop->apiHelper();
        $scripts = $apiHelper->getScriptTags();

        $created = [];
        $deleted = [];
        $used = [];

        foreach ($configScripts as $scripttag) {
            if (! $this->checkExists($scripttag, $scripts)) {
                $apiHelper->createScriptTag($scripttag);
                $created[] = $scripttag;
            }

            $used[] = $scripttag['src'];
        }

        foreach ($scripts as $scriptTag) {
            if (! in_array($scriptTag->src, $used)) {
                $apiHelper->deleteScriptTag($scriptTag->id);
                $deleted[] = $scriptTag;
            }
        }

        return [
            'created' => $created,
            'deleted' => $deleted,
        ];
    }

    private function checkExists(array $script, ResponseAccess $scripts): bool
    {
        foreach ($scripts as $shopScript) {
            if ($shopScript['src'] === $script['src']) {
                return true;
            }
        }

        return false;
    }
}
