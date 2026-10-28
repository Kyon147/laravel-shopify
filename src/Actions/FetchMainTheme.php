<?php

declare(strict_types=1);

namespace Osiset\ShopifyApp\Actions;

use Illuminate\Support\Facades\Log;
use Osiset\ShopifyApp\Contracts\ShopModel;

final class FetchMainTheme
{
    /**
     * @return array{id: ?string, name: ?string}
     */
    public function handle(ShopModel $shop): array
    {
        $response = $shop->api()->graph('{
            themes(first: 1, roles: MAIN) {
                nodes {
                    id
                    name
                }
            }
        }');

        if ($response['errors']) {
            Log::error('Fetching main theme error: '.json_encode($response['errors']));

            return [];
        }

        return data_get($response['body']->toArray(), 'data.themes.nodes.0', []);
    }
}
