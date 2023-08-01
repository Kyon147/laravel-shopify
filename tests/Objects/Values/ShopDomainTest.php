<?php

namespace Osiset\ShopifyApp\Test\Objects\Values;

use Osiset\ShopifyApp\Exceptions\InvalidShopDomainException;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;
use Osiset\ShopifyApp\Test\TestCase;

class ShopDomainTest extends TestCase
{
    public function testAddsMyshopifyDomainIfNotContainsPeriod()
    {
        $shopDomain = ShopDomain::fromNative('test');

        $this->assertEquals('test.myshopify.com', $shopDomain->toNative());
    }

    public function testDoesNotAddMyshopifyDomainIfContainsPeriod()
    {
        $shopDomain = ShopDomain::fromNative('test.myshopify.com');

        $this->assertEquals('test.myshopify.com', $shopDomain->toNative());
    }

    public function testStripsTheProtocol()
    {
        $shopDomainA = ShopDomain::fromNative('https://test.myshopify.com');
        $shopDomainB = ShopDomain::fromNative('http://test.myshopify.com');

        $this->assertEquals('test.myshopify.com', $shopDomainA->toNative());
        $this->assertEquals('test.myshopify.com', $shopDomainB->toNative());
    }

    public function testDoesNotAcceptNonMyshopifyDomains()
    {
        $this->expectException(InvalidShopDomainException::class);

        ShopDomain::fromNative('test.github.com');
    }

    public function testDoesNotAcceptNonMyshopifyDomainsWithSinglePeriod()
    {
        $this->expectException(InvalidShopDomainException::class);

        ShopDomain::fromNative('test.invalid-domain');
    }
}
