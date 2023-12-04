<?php

namespace Osiset\ShopifyApp\Traits;


use Illuminate\Http\Request;
use Illuminate\Http\Response as ResponseResponse;
use Illuminate\Support\Facades\Response;
use Osiset\ShopifyApp\Interfaces\ProductJobInterface;
use Osiset\ShopifyApp\Services\ProductJobService;
use Osiset\ShopifyApp\Util;

/**
 * Responsible for handling incoming webhook requests.
 */
trait WebhookController
{
    /**
     * Handles an incoming webhook.
     *
     * @param string  $type    The type of webhook
     * @param Request $request The request object.
     *
     * @return ResponseResponse
     */
    public function handle(string $type, Request $request, ProductJobInterface $productJobService): ResponseResponse
    {
        // Get the job class and dispatch
        $job_name = str_replace('-', '', ucwords($type, '-'));
        $jobClass = Util::getShopifyConfig('job_namespace').$job_name.'Job';
        $jobQueue = Util::getShopifyConfig('job_queues')['webhooks'];

        if (isset(Util::getShopifyConfig('job_queues')[$type])) {
            $jobQueue = Util::getShopifyConfig('job_queues')[$type];
        }

        $jobData = json_decode($request->getContent());

        $count_product_job = $productJobService->getCount('count_products');

        if (!in_array($job_name, ['ProductsUpdate','ProductsDelete'])
            || $count_product_job == config('app.products_job.count')) {

            if (in_array($job_name,ProductJobService::eventType)) {
                $payload_ids = $productJobService->getItems();
                $payload_ids[] = $jobData->id;
                foreach ($payload_ids as $payload_id) {
                    $obj = new \stdClass();
                    $obj->id = $payload_id;
                    $this->onQueueSend($jobClass, $obj, $request, $jobQueue);
                }
                $productJobService->resetCount('count_products');
            } else {
                $this->onQueueSend($jobClass, $jobData, $request, $jobQueue);
            }

        } else {
            $productJobService->setCount('count_products');
            $productJobService->setItem($jobData->id);
        }
        return Response::make('', ResponseResponse::HTTP_CREATED);
    }

    private function onQueueSend(string $jobClass, \stdClass $jobData, Request $request, string $jobQueue)
    {
        $jobClass::dispatch(
            $request->header('x-shopify-shop-domain'),
            $jobData
        )->onQueue($jobQueue);
    }
}
