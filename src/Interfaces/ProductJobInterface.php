<?php


namespace Osiset\ShopifyApp\Interfaces;


interface ProductJobInterface
{
    public function getCount(string $redis_key): int;
    public function setCount(string $redis_key): void;
    public function resetCount(string $redis_key): void;
    public function setItem(int $payload_id): bool;
    public function getItems(): ?array;
}
