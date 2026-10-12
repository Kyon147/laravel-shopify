<?php

namespace Osiset\ShopifyApp\Test\Console;

use Osiset\ShopifyApp\Test\Stubs\Api as ApiStub;
use Osiset\ShopifyApp\Test\TestCase;

class RemoveScriptTagCommandTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->setApiStub();
    }

    public function testRemovesAllScriptTags(): void
    {
        $shop = factory($this->model)->create();
        ApiStub::stubResponses(['get_script_tags', 'empty', 'empty']);

        $this
            ->artisan("shopify-app:remove-script-tag {$shop->name}")
            ->expectsOutput('  - Removed 421379493 (https://js-aplenty.com/bar.js)')
            ->expectsOutput('  - Removed 596726825 (https://js-aplenty.com/foo.js)')
            ->expectsOutput("Removed 2 script tag(s) from {$shop->name}.")
            ->assertExitCode(0);

        $this->assertEmpty(ApiStub::$stubFiles);
    }

    public function testRemovesSingleScriptTagById(): void
    {
        $shop = factory($this->model)->create();
        ApiStub::stubResponses(['get_script_tags', 'empty']);

        $this
            ->artisan("shopify-app:remove-script-tag {$shop->name} --id=596726825")
            ->expectsOutput('  - Removed 596726825 (https://js-aplenty.com/foo.js)')
            ->doesntExpectOutput('  - Removed 421379493 (https://js-aplenty.com/bar.js)')
            ->expectsOutput("Removed 1 script tag(s) from {$shop->name}.")
            ->assertExitCode(0);

        $this->assertEmpty(ApiStub::$stubFiles);
    }

    public function testFailsForUnknownScriptTagId(): void
    {
        $shop = factory($this->model)->create();
        ApiStub::stubResponses(['get_script_tags']);

        $this
            ->artisan("shopify-app:remove-script-tag {$shop->name} --id=123")
            ->expectsOutput("Script tag 123 not found for {$shop->name}.")
            ->assertExitCode(1);
    }

    public function testDryRunDoesNotDelete(): void
    {
        $shop = factory($this->model)->create();
        ApiStub::stubResponses(['get_script_tags', 'empty']);

        $this
            ->artisan("shopify-app:remove-script-tag {$shop->name} --dry-run")
            ->expectsOutput('  - 421379493 (https://js-aplenty.com/bar.js)')
            ->expectsOutput('  - 596726825 (https://js-aplenty.com/foo.js)')
            ->expectsOutput("Dry run — 2 script tag(s) would be removed from {$shop->name}.")
            ->assertExitCode(0);

        // The delete stub was never consumed
        $this->assertSame(['empty'], ApiStub::$stubFiles);
    }

    public function testNoScriptTags(): void
    {
        $shop = factory($this->model)->create();
        ApiStub::stubResponses(['get_script_tags_empty']);

        $this
            ->artisan("shopify-app:remove-script-tag {$shop->name}")
            ->expectsOutput("No script tags found for {$shop->name}.")
            ->assertExitCode(0);
    }

    public function testFailsForUnknownShop(): void
    {
        $this
            ->artisan('shopify-app:remove-script-tag unknown-shop.myshopify.com')
            ->expectsOutput('Shop unknown-shop.myshopify.com not found.')
            ->assertExitCode(1);
    }
}
