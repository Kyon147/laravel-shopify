<?php

namespace Osiset\ShopifyApp\Test\Stubs;

use Gnikyt\BasicShopifyAPI\Options;
use Gnikyt\BasicShopifyAPI\ResponseAccess;
use Osiset\ShopifyApp\Util;
use RuntimeException;

/**
 * Keep Shopify's charge data separate from browser callback parameters.
 */
class BillingApi extends Api
{
    public static array $charges = [];
    public static array $requests = [];
    public static array $activationOverrides = [];
    private static int $nextId = 1001;

    public static function install(): void
    {
        self::$charges = [];
        self::$requests = [];
        self::$activationOverrides = [];
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
        if (!preg_match('#/(recurring_application_charge|application_charge)s(?:/([0-9]+)(/activate)?)?\.json$#D', $path, $matches)) {
            throw new RuntimeException('Unexpected billing request: '.$method.' '.$path);
        }

        $type = $matches[1];
        if (!isset($matches[2]) && $method === 'POST') {
            $charge = $this->create($params[$type], $type);
        } else {
            $id = (int) $matches[2];
            $charge = self::$charges[$shop][$id] ?? null;
            if (!$charge || $charge['charge_type'] !== $type) {
                throw new RuntimeException('Charge is unavailable for this store and type.');
            }
            if ($method === 'POST' && isset($matches[3])) {
                $charge = array_merge($charge, ['status' => 'active'], self::$activationOverrides);
                self::$charges[$shop][$id] = $charge;
            } elseif ($method !== 'GET') {
                throw new RuntimeException('Unexpected billing request: '.$method.' '.$path);
            }
        }

        return $this->response([$type => $charge]);
    }

    public function graph(string $query, array $variables = [], bool $sync = true): array
    {
        if (!str_contains($query, 'appSubscriptionCreate')) {
            throw new RuntimeException('Unexpected billing GraphQL request.');
        }

        $pricing = $variables['lineItems'][0]['plan']['appRecurringPricingDetails'];
        $charge = $this->create([
            'name' => $variables['name'],
            'price' => $pricing['price']['amount'],
            'interval' => $pricing['interval'],
            'test' => $variables['test'],
            'trial_days' => $variables['trialDays'],
            'return_url' => $variables['returnUrl'],
        ], 'recurring_application_charge');

        return $this->response(['data' => ['appSubscriptionCreate' => [
            'appSubscription' => ['id' => 'gid://shopify/AppSubscription/'.$charge['id']],
            'confirmationUrl' => $charge['confirmation_url'],
            'userErrors' => [],
        ]]]);
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
