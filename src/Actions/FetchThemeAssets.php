<?php

declare(strict_types=1);

namespace Osiset\ShopifyApp\Actions;

use Illuminate\Support\Facades\Log;
use Osiset\ShopifyApp\Contracts\ShopModel;

final class FetchThemeAssets
{
    /**
     * @param  array<int, array{filename: string, content: string}>  $filenames
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
            'filenames' => $filenames,
        ]);

        if (blank(data_get($response['body']->toArray(), 'data.theme.userErrors'))) {
            return array_map(fn (array $data) => [
                'filename' => $data['filename'],
                'content' => $data['body']['content'] ?? '',
            ], data_get($response['body']->toArray(), 'data.theme.files.nodes'));
        }

        Log::error('Fetching settings data error: ' . json_encode(data_get($response['body']->toArray(), 'data.theme.userErrors')));

        return [];
    }
}
