<?php

namespace Osiset\ShopifyApp\Test\Actions;

use Illuminate\Support\Facades\Queue;
use Osiset\ShopifyApp\Actions\AfterAuthorize;
use Osiset\ShopifyApp\Test\Stubs\AfterAuthorizeJob;
use Osiset\ShopifyApp\Test\TestCase;

require_once __DIR__.'/../Stubs/AfterAuthorizeJob.php';

class AfterAuthorizeTest extends TestCase
{
    /**
     * @var \Osiset\ShopifyApp\Actions\AfterAuthorize
     */
    protected $action;

    public function setUp(): void
    {
        parent::setUp();

        $this->action = $this->app->make(AfterAuthorize::class);
    }

    public function testRunDispatch(): void
    {
        // Fake the queue
        Queue::fake();

        // Create the config
        $jobClass = AfterAuthorizeJob::class;
        $this->app['config']->set('shopify-app.after_authenticate_job', [
            [
                'job' => $jobClass,
                'inline' => false,
            ],
            [
                'job' => $jobClass,
                'inline' => false,
            ],
        ]);

        // Create the shop
        $shop = factory($this->model)->create();

        // Run
        call_user_func(
            $this->action,
            $shop->getId()
        );

        Queue::assertPushed($jobClass);
    }

    public function testRunDispatchCustomConnection(): void
    {
        // Fake the queue
        Queue::fake();

        // Create the config
        $jobClass = AfterAuthorizeJob::class;
        $this->app['config']->set('shopify-app.after_authenticate_job', [
            [
                'job' => $jobClass,
                'inline' => false,
            ],
            [
                'job' => $jobClass,
                'inline' => false,
            ],
        ]);

        // Define the custom job connection
        $customConnection = 'custom_connection';

        // Set up the configuration
        $this->app['config']->set('shopify-app.job_connections', [
            'after_authenticate' => $customConnection,
        ]);

        // Create the shop
        $shop = factory($this->model)->create();

        // Run
        call_user_func(
            $this->action,
            $shop->getId()
        );

        // Assert the job was pushed with the correct connection
        Queue::assertPushed($jobClass, function ($job) use ($customConnection) {
            return $job->connection === $customConnection;
        });
    }

    public function testRunInline(): void
    {
        // Create the config
        $jobClass = AfterAuthorizeJob::class;
        $this->app['config']->set('shopify-app.after_authenticate_job', [
            'job' => $jobClass,
            'inline' => true,
        ]);

        // Create the shop
        $shop = factory($this->model)->create();

        // Run
        $result = call_user_func(
            $this->action,
            $shop->getId()
        );

        $this->assertTrue($result);
    }

    public function testRunNoJobs(): void
    {
        // Create the config
        $this->app['config']->set('shopify-app.after_authenticate_job', []);

        // Create the shop
        $shop = factory($this->model)->create();

        // Run
        $result = call_user_func(
            $this->action,
            $shop->getId()
        );

        $this->assertFalse($result);
    }
}
