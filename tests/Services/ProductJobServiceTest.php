<?php


namespace Osiset\ShopifyApp\Test\Services;


use Osiset\ShopifyApp\Interfaces\ProductJobInterface;
use Osiset\ShopifyApp\Services\ProductJobService;
use Osiset\ShopifyApp\Test\TestCase;

class ProductJobServiceTest extends TestCase
{
    protected $redis_key;
    protected ProductJobInterface $productJobService;

    public function setUp(): void
    {
        parent::setUp();
        $this->redis_key = 'count_products';
        $this->productJobService = new ProductJobService();
    }

    public function testGetCount(): int
    {
        $this->assertIsInt($this->productJobService->getCount($this->redis_key));
        return 1;
    }

    public function testSetCount(): void
    {
        $this->assertNull($this->productJobService->setCount($this->redis_key));
    }

    public function testResetCount(): void
    {
        $this->assertNull($this->productJobService->resetCount($this->redis_key));
    }

    public function testSetItem(): void
    {
        $this->assertIsBool($this->productJobService->setItem(1));
    }

}
