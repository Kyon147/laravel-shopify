<?php

namespace Osiset\ShopifyApp\Test\Stubs;

use Gnikyt\BasicShopifyAPI\Options;
use Gnikyt\BasicShopifyAPI\ResponseAccess;
use Osiset\ShopifyApp\Util;
use RuntimeException;

/**
 * Keep Shopify's charge data separate from browser callback parameters.
 *
 * Charges are stored with the keys the REST Admin API used. Subscriptions are served through the
 * GraphQL Admin API; one-time charges are read through REST, which alone returns their return URL.
 */
class BillingApi extends Api
{
    public static array $charges = [];
    public static array $requests = [];

    /**
     * Changes Shopify makes to a charge before it is read a second time, such as activating it.
     */
    public static array $readBack = [];

    private static array $reads = [];
    private static int $nextId = 1001;

    public static function install(): void
    {
        self::$charges = [];
        self::$requests = [];
        self::$readBack = [];
        self::$reads = [];
        self::$nextId = 1001;
        config(['shopify-app.api_init' => static function (Options $options): self {
            $ts = Util::getShopifyConfig('api_time_store');
            $ls = Util::getShopifyConfig('api_limit_store');
            $sd = Util::getShopifyConfig('api_deferrer');

            return new self($options, new $ts(), new $ls(), new $sd());
        }]);
    }

    public static function approve(string $shop, int $id, string $status = 'active'): void
    {
        self::$charges[$shop][$id]['status'] = $status;
    }

    public function rest(string $method, string $path, ?array $params = null, array $headers = [], bool $sync = true): array
    {
        $shop = $this->getSession()->getShop();
        self::$requests[] = [$shop, $method, $path];
        if ($method !== 'GET' || !preg_match('#/application_charges/([0-9]+)\.json$#D', $path, $matches)) {
            throw new RuntimeException('Unexpected billing request: '.$method.' '.$path);
        }

        $charge = $this->read($shop, (int) $matches[1]);
        if (!$charge || $charge['charge_type'] !== 'application_charge') {
            throw new RuntimeException('Charge is unavailable for this store and type.');
        }

        return $this->response(['application_charge' => $charge]);
    }

    public function graph(string $query, array $variables = [], bool $sync = true): array
    {
        $shop = $this->getSession()->getShop();
        if (!preg_match('/^\s*(query|mutation)\s+(\w+)/', $query, $operation)) {
            throw new RuntimeException('Unexpected billing GraphQL request.');
        }
        self::$requests[] = [$shop, $operation[1], $operation[2]];

        if ($operation[2] === 'appSubscription') {
            return $this->response(['data' => ['node' => $this->subscriptionNode($shop, $variables['id'])]]);
        }

        if ($operation[2] === 'appSubscriptionCreate') {
            $plans = array_merge(...array_column($variables['lineItems'], 'plan'));
            $charge = $this->create([
                'name' => $variables['name'],
                'price' => $plans['appRecurringPricingDetails']['price']['amount'],
                'interval' => $plans['appRecurringPricingDetails']['interval'],
                'capped_amount' => $plans['appUsagePricingDetails']['cappedAmount']['amount'] ?? null,
                'terms' => $plans['appUsagePricingDetails']['terms'] ?? null,
                'test' => $variables['test'],
                'trial_days' => $variables['trialDays'],
                'return_url' => $variables['returnUrl'],
            ], 'recurring_application_charge');

            return $this->response(['data' => ['appSubscriptionCreate' => [
                'appSubscription' => ['id' => 'gid://shopify/AppSubscription/'.$charge['id'], 'status' => 'PENDING'],
                'confirmationUrl' => $charge['confirmation_url'],
                'userErrors' => [],
            ]]]);
        }

        if ($operation[2] === 'appPurchaseOneTimeCreate') {
            $charge = $this->create([
                'name' => $variables['name'],
                'price' => $variables['price']['amount'],
                'test' => $variables['test'],
                'return_url' => $variables['returnUrl'],
            ], 'application_charge');

            return $this->response(['data' => ['appPurchaseOneTimeCreate' => [
                'appPurchaseOneTime' => ['id' => 'gid://shopify/AppPurchaseOneTime/'.$charge['id'], 'status' => 'PENDING'],
                'confirmationUrl' => $charge['confirmation_url'],
                'userErrors' => [],
            ]]]);
        }

        throw new RuntimeException('Unexpected billing GraphQL request: '.$operation[2]);
    }

    /**
     * A stored charge, with $readBack applied from its second read on.
     */
    private function read(string $shop, int $id): ?array
    {
        if (!isset(self::$charges[$shop][$id])) {
            return null;
        }

        $key = $shop.'#'.$id;
        self::$reads[$key] = (self::$reads[$key] ?? 0) + 1;
        if (self::$reads[$key] > 1 && self::$readBack !== []) {
            self::$charges[$shop][$id] = array_merge(self::$charges[$shop][$id], self::$readBack);
        }

        return self::$charges[$shop][$id];
    }

    /**
     * Render a stored subscription the way the GraphQL Admin API returns it, or no node for another store's.
     */
    private function subscriptionNode(string $shop, string $gid): ?array
    {
        if (!preg_match('#^gid://shopify/AppSubscription/([0-9]+)$#D', $gid, $matches)) {
            throw new RuntimeException('Unexpected subscription ID: '.$gid);
        }

        $charge = $this->read($shop, (int) $matches[1]);
        if (!$charge || $charge['charge_type'] !== 'recurring_application_charge') {
            return null;
        }

        $lineItems = [[
            'id' => 'gid://shopify/AppSubscriptionLineItem/'.$charge['id'].'?v=1&index=0',
            'plan' => ['pricingDetails' => [
                '__typename' => 'AppRecurringPricing',
                'interval' => $charge['interval'] ?? 'EVERY_30_DAYS',
                'price' => ['amount' => $charge['price'], 'currencyCode' => 'USD'],
            ]],
        ]];
        if (isset($charge['capped_amount'])) {
            $lineItems[] = [
                'id' => 'gid://shopify/AppSubscriptionLineItem/'.$charge['id'].'?v=1&index=1',
                'plan' => ['pricingDetails' => [
                    '__typename' => 'AppUsagePricing',
                    'terms' => $charge['terms'] ?? null,
                    'cappedAmount' => ['amount' => $charge['capped_amount'], 'currencyCode' => 'USD'],
                    'balanceUsed' => ['amount' => '0.0'],
                ]],
            ];
        }

        return [
            '__typename' => 'AppSubscription',
            'id' => 'gid://shopify/AppSubscription/'.$charge['id'],
            'name' => $charge['name'],
            'returnUrl' => $charge['return_url'],
            'status' => is_string($charge['status']) ? strtoupper($charge['status']) : $charge['status'],
            'test' => $charge['test'],
            'trialDays' => $charge['trial_days'],
            'createdAt' => '2026-10-21T00:00:00Z',
            'currentPeriodEnd' => $charge['billing_on'],
            'lineItems' => $lineItems,
        ];
    }

    private function create(array $details, string $type): array
    {
        $shop = $this->getSession()->getShop();
        $id = self::$nextId++;
        $charge = array_merge($details, [
            'id' => $id,
            'charge_type' => $type,
            'status' => 'pending',
            'activated_on' => '2026-10-21',
            'billing_on' => '2026-11-20',
            'trial_ends_on' => ($details['trial_days'] ?? 0) > 0 ? '2026-10-24' : null,
            'confirmation_url' => 'https://'.$shop.'/admin/charges/'.$id.'/confirm',
        ]);
        self::$charges[$shop][$id] = $charge;

        return $charge;
    }

    private function response(array $body): array
    {
        return ['errors' => false, 'exception' => null, 'status' => 200, 'body' => new ResponseAccess($body)];
    }
}
