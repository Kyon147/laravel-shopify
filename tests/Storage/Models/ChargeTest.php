<?php

namespace Osiset\ShopifyApp\Test\Storage\Models;

use Osiset\ShopifyApp\Contracts\ShopModel as IShopModel;
use Osiset\ShopifyApp\Objects\Enums\ChargeStatus;
use Osiset\ShopifyApp\Objects\Enums\ChargeType;
use Osiset\ShopifyApp\Objects\Values\ChargeId;
use Osiset\ShopifyApp\Objects\Values\ChargeReference;
use Osiset\ShopifyApp\Storage\Models\Charge;
use Osiset\ShopifyApp\Test\TestCase;
use Osiset\ShopifyApp\Util;

class ChargeTest extends TestCase
{
    public function testModel(): void
    {
        // Create a shop
        $shop = factory($this->model)->create();

        // Create a charge
        $charge = factory(Util::getShopifyConfig('models.charge', Charge::class))->states('type_recurring')->create([
            'user_id' => $shop->getId()->toNative(),
        ]);

        $this->assertInstanceOf(ChargeId::class, $charge->getId());
        $this->assertInstanceOf(ChargeReference::class, $charge->getReference());
        $this->assertInstanceOf(IShopModel::class, $charge->shop);
        $this->assertNull($charge->plan);
        $this->assertFalse($charge->isTest());
        $this->assertFalse($charge->isTrial());
        $this->assertTrue($charge->isType(ChargeType::RECURRING));
        $this->assertTrue($charge->isStatus(ChargeStatus::ACCEPTED));
        $this->assertFalse($charge->isActive());
        $this->assertTrue($charge->isAccepted());
        $this->assertFalse($charge->isDeclined());
        $this->assertFalse($charge->isCancelled());
        $this->assertFalse($charge->isExpired());
        $this->assertFalse($charge->isFrozen());
        $this->assertFalse($charge->isOngoing());
        $this->assertSame('recurring_application_charge', $charge->getTypeApiString());
        $this->assertSame('recurring_application_charges', $charge->getTypeApiString(true));
    }

    /**
     * Shopify reports a subscription as FROZEN when the shop stops paying, and as EXPIRED when it
     * is never approved. A charge stored with either status must still be readable.
     *
     * @dataProvider statusProvider
     */
    public function testStatusesShopifyReports(string $status, string $check): void
    {
        $shop = factory($this->model)->create();
        $charge = factory(Util::getShopifyConfig('models.charge', Charge::class))->states('type_recurring')->create([
            'user_id' => $shop->getId()->toNative(),
            'status' => $status,
        ]);

        $this->assertSame(ChargeStatus::fromNative($status), $charge->getStatus());
        $this->assertTrue($charge->{$check}());
        $this->assertFalse($charge->isActive());
        $this->assertFalse($charge->isAccepted());
        $this->assertFalse($charge->isDeclined());
        $this->assertFalse($charge->isCancelled());
        $this->assertFalse($charge->isOngoing());
    }

    public static function statusProvider(): array
    {
        return [
            'frozen' => ['FROZEN', 'isFrozen'],
            'expired' => ['EXPIRED', 'isExpired'],
        ];
    }
}
