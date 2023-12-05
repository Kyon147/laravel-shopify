<?php


namespace Osiset\ShopifyApp\Services;

use Osiset\ShopifyApp\Interfaces\ProductJobInterface;
use Illuminate\Support\Facades\Cache;
use Exception;

class ProductJobService implements ProductJobInterface
{
    public const eventType = ['ProductsUpdate','ProductsDelete'];

    public function getCount(string $redis_key): int
    {
        return Cache::get($redis_key) ?? 1;
    }

    public function setCount(string $redis_key): void
    {
        Cache::increment($redis_key);
    }

    public function resetCount(string $redis_key): void
    {
        try {
            Cache::set($redis_key, 1);
            Cache::set('products.job','');
        } catch (\Exception $exception) {
            throw new Exception($exception->getMessage());
        }
    }

    public function setItem(int $payload_id): bool
    {
        $products = [];
        $payload_ids = $this->getItems();
        if ($payload_ids) {
            foreach ($payload_ids as $id) {
                $products[] = $id;
            }
        }
        $products[] = $payload_id;
        return Cache::set('products.job',json_encode($products));
    }

    public function getItems(): ?array
    {
        return json_decode(Cache::get('products.job'),true);
    }
}
