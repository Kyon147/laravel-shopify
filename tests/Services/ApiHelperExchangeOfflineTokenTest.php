<?php

namespace Osiset\ShopifyApp\Test\Services;

use Osiset\ShopifyApp\Contracts\ApiHelper as IApiHelper;
use Osiset\ShopifyApp\Test\Stubs\Api as ApiStub;
use Osiset\ShopifyApp\Test\TestCase;

class ApiHelperExchangeOfflineTokenTest extends TestCase
{
    public function testExchangeNonExpiringOfflineTokenForExpiring(): void
    {
        $shop = factory($this->model)->create(['password' => 'shpat_legacy']);

        $this->setApiStub();
        ApiStub::stubResponses(['access_token_expiring']);

        $data = $this->app->make(IApiHelper::class)
            ->exchangeNonExpiringOfflineTokenForExpiring($shop->name, $shop->password);

        $this->assertSame('shpat_expiring_test_token', $data['access_token']);
        $this->assertSame('shprt_expiring_test_refresh', $data['refresh_token']);
        $this->assertSame(3600, $data['expires_in']);
    }
}
