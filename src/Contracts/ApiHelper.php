<?php

namespace Osiset\ShopifyApp\Contracts;

use Gnikyt\BasicShopifyAPI\BasicShopifyAPI;
use Gnikyt\BasicShopifyAPI\ResponseAccess;
use Gnikyt\BasicShopifyAPI\Session;
use GuzzleHttp\Exception\RequestException;
use Osiset\ShopifyApp\Objects\Enums\AuthMode;
use Osiset\ShopifyApp\Objects\Enums\ChargeType;
use Osiset\ShopifyApp\Objects\Transfers\PlanDetails;
use Osiset\ShopifyApp\Objects\Transfers\UsageChargeDetails;
use Osiset\ShopifyApp\Objects\Values\ChargeReference;

/**
 * Represents the API helper.
 */
interface ApiHelper
{
    /**
     * Create an API instance (without a context to a shop).
     *
     * @param Session|null $session The shop API session details.
     *
     * @return self
     */
    public function make(Session $session = null);

    /**
     * Set an existing API instance.
     *
     * @param BasicShopifyAPI $api The API instance.
     *
     * @return self
     */
    public function setApi(BasicShopifyAPI $api);

    /**
     * Get the existing instance.
     *
     * @return BasicShopifyAPI
     */
    public function getApi(): BasicShopifyAPI;

    /**
     * Build the authentication URL to Shopify.
     *
     * @param AuthMode $mode   The mode of authentication (offline or per-user).
     * @param string   $scopes The scopes for the authentication, comma-separated.
     *
     * @return string
     */
    public function buildAuthUrl(AuthMode $mode, string $scopes): string;

    /**
     * Determines if the request HMAC is verified.
     *
     * @param array $request The request parameters.
     *
     * @return bool
     */
    public function verifyRequest(array $request): bool;

    /**
     * Finish the process by getting the access details from the code.
     *
     * @param string $code The code from the request.
     *
     * @return ResponseAccess
     */
    public function getAccessData(string $code): ResponseAccess;

    /**
     * Get the script tags for the shop.
     *
     * @param array $params The params to set to the request.
     *
     * @throws RequestException
     *
     * @return ResponseAccess
     */
    public function getScriptTags(array $params = []): ResponseAccess;

    /**
     * Create a script tag for the shop.
     *
     * @param array $payload The data for the script tag creation.
     *
     * @throws RequestException
     *
     * @return ResponseAccess
     */
    public function createScriptTag(array $payload): ResponseAccess;

    /**
     * Delete a script.
     *
     * @param int $scriptTagId The script tag ID to delete.
     *
     * @return void
     */
    public function deleteScriptTag(int $scriptTagId);

    /**
     * Get the charge record.
     *
     * @param ChargeType      $chargeType The type of charge (plural).
     * @param ChargeReference $chargeRef  The charge ID.
     *
     * @throws RequestException
     *
     * @return ResponseAccess
     */
    public function getCharge(ChargeType $chargeType, ChargeReference $chargeRef): ResponseAccess;

    /**
     * Activate a charge.
     *
     * @param ChargeType      $chargeType The type of charge (plural).
     * @param ChargeReference $chargeRef  The charge ID.
     *
     * @throws RequestException
     *
     * @return ResponseAccess
     */
    public function activateCharge(ChargeType $chargeType, ChargeReference $chargeRef): ResponseAccess;

    /**
     * Create a charge.
     *
     * @param ChargeType  $chargeType The type of charge (plural).
     * @param PlanDetails $payload    The data for the charge creation.
     *
     * @return ResponseAccess
     */
    public function createCharge(ChargeType $chargeType, PlanDetails $payload): ResponseAccess;

    /**
     * Create a charge using GraphQL.
     *
     * @param PlanDetails $payload    The data for the charge creation.
     *
     * @return ResponseAccess
     */
    public function createChargeGraphQL(PlanDetails $payload): ResponseAccess;

    /**
     * Get webhooks for the shop.
     *
     * @param array $params The params to set to the request.
     *
     * @throws RequestException
     *
     * @return ResponseAccess
     */
    public function getWebhooks(array $params = []): ResponseAccess;

    /**
     * Create a webhook.
     *
     * @param array $payload The data for the webhook creation.
     *
     * @return ResponseAccess
     */
    public function createWebhook(array $payload): ResponseAccess;

    /**
     * Delete a webhook.
     *
     * @param string $webhookId The webhook ID to delete.
     *
     * @return ResponseAccess
     */
    public function deleteWebhook(string $webhookId): ResponseAccess;

    /**
     * Creates a usage charge for a recurring charge.
     *
     * @param UsageChargeDetails $payload The data for the usage charge creation.
     *
     * @return ResponseAccess|bool Array if success, bool for error.
     */
    public function createUsageCharge(UsageChargeDetails $payload);
}
