<?php

declare(strict_types=1);

namespace Osiset\ShopifyApp\Actions;

use Illuminate\Support\Facades\Log;
use Osiset\ShopifyApp\Contracts\ShopModel;

final class FetchThemeAssets
{
    /**
     * @param  string[]  $filenames
     */
    public function handle(ShopModel $shop, string $mainThemeId, array $filenames): array
    {
        $response = $shop->api()->graph('query ($id: ID!, $filenames: [String!]) {
            theme(id: $id) {
                id
                name
                role
                files(filenames: $filenames) {
                    nodes {
                        filename
                        body {
                            ... on OnlineStoreThemeFileBodyText {
                                content
                            }
                        }
                    }
                }
            }
        }', [
            'id' => $mainThemeId,
            'filenames' => array_values($filenames),
        ]);

        if ($response['errors']) {
            Log::error('Fetching settings data error: '.json_encode($response['errors']));

            return [];
        }

        $nodes = data_get($response['body']->toArray(), 'data.theme.files.nodes', []);

        return array_values(array_filter(array_map(function (array $data) {
            $content = data_get($data, 'body.content');

            if ($content === null) {
                Log::warning('Theme file body is not text, skipping: '.data_get($data, 'filename', '?'));

                return null;
            }

            return [
                'filename' => $data['filename'],
                'content' => $content,
            ];
        }, $nodes)));
    }
}
