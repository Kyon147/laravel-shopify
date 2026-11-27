<?php

namespace Osiset\ShopifyApp\Services;

use Closure;
use Exception;
use Gnikyt\BasicShopifyAPI\BasicShopifyAPI;
use Gnikyt\BasicShopifyAPI\Options;
use Gnikyt\BasicShopifyAPI\ResponseAccess;
use Gnikyt\BasicShopifyAPI\Session;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Osiset\ShopifyApp\Contracts\ApiHelper as IApiHelper;
use Osiset\ShopifyApp\Exceptions\ApiException;
use Osiset\ShopifyApp\Objects\Enums\ApiMethod;
use Osiset\ShopifyApp\Objects\Enums\AuthMode;
use Osiset\ShopifyApp\Objects\Enums\ChargeInterval;
use Osiset\ShopifyApp\Objects\Enums\ChargeType;
use Osiset\ShopifyApp\Objects\Enums\DataSource;
use Osiset\ShopifyApp\Objects\Transfers\PlanDetails as PlanDetailsTransfer;
use Osiset\ShopifyApp\Objects\Transfers\UsageChargeDetails as UsageChargeDetailsTransfer;
use Osiset\ShopifyApp\Objects\Values\ChargeReference;
use Osiset\ShopifyApp\Objects\Values\NullableShopDomain;
use Osiset\ShopifyApp\Util;
use Throwable;

/**
 * Basic helper class for API calls to Shopify.
 */
class ApiHelper implements IApiHelper
{
    /**
     * The API instance.
     *
     * @var BasicShopifyAPI
     */
    protected $api;

    /**
     * {@inheritdoc}
     */
    public function make(?Session $session = null): self
    {
        // Create the options
        $opts = new Options();

        $shop = $this->getShopDomain($session)->toNative();
        $opts->setApiKey(Util::getShopifyConfig('api_key', $shop));
        $opts->setApiSecret(Util::getShopifyConfig('api_secret', $shop));
        $opts->setVersion(Util::getShopifyConfig('api_version', $shop));

        // Create the instance
        if (Util::getShopifyConfig('api_init')) {
            // User-defined init function
            $this->api = call_user_func(
                Util::getShopifyConfig('api_init'),
                $opts,
                $session,
                Request::all()
            );
        } else {
            // Default init
            $ts = Util::getShopifyConfig('api_time_store', $shop);
            $ls = Util::getShopifyConfig('api_limit_store', $shop);
            $sd = Util::getShopifyConfig('api_deferrer', $shop);

            $this->api = new BasicShopifyAPI(
                $opts,
                new $ts(),
                new $ls(),
                new $sd()
            );
        }

        // Set session?
        if ($session !== null) {
            // Set the session to the shop's domain/token
            $this->api->setSession($session);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setApi(BasicShopifyAPI $api): self
    {
        $this->api = $api;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getApi(): BasicShopifyAPI
    {
        return $this->api;
    }

    /**
     * Run a one-time function with an API instance.
     * The instance state is reset to the previous state.
     *
     * @param BasicShopifyAPI $api The API instance.
     * @param Closure         $fn  The function to call.
     *
     * @return void
     */
    public function withApi(BasicShopifyAPI $api, Closure $fn): void
    {
        // Save current instance
        $currentApi = $this->api;

        // Run the function, inject the temporary instance
        $this->api = $api;
        $fn($this);

        // Put the previous instance back
        $this->api = $currentApi;
    }

    /**
     * {@inheritdoc}
     */
    public function buildAuthUrl(AuthMode $mode, string $scopes): string
    {
        // Fix for peruser => per-user
        $mode = $mode->isSame(AuthMode::PERUSER()) ? 'PER-USER' : $mode->toNative();

        return $this->api->getAuthUrl(
            $scopes,
            URL::secure(Util::getShopifyConfig('api_redirect')),
            strtolower($mode)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function verifyRequest(array $request): bool
    {
        $secret = $this->api->getOptions()->getApiSecret();
        if ($secret === null
            || ! isset($request['shop'], $request['timestamp'], $request['hmac'])
            || ! is_string($request['hmac'])
        ) {
            // Missing secret or params, keep the existing behaviour
            return $this->api->verifyRequest($request);
        }

        $params = $request;
        unset($params['hmac']);
        foreach ($params as $value) {
            if (! is_scalar($value) && $value !== null) {
                // Arrays are not escaped by Shopify the same way, keep the existing behaviour
                return $this->api->verifyRequest($request);
            }
        }

        // Rebuild the message the way Shopify signs it, escaping "%" and "&" (and "=" in keys)
        ksort($params);
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = str_replace(['%', '&', '='], ['%25', '%26', '%3D'], (string) $key)
                .'='.str_replace(['%', '&'], ['%25', '%26'], (string) $value);
        }

        return hash_equals(hash_hmac('sha256', implode('&', $pairs), $secret), $request['hmac']);
    }

    /**
     * {@inheritdoc}
     */
    public function performOfflineTokenExchange(string $token): ResponseAccess
    {
        $shop = $this->getShopDomain($this->api->getSession())->toNative();
        $data = [
            'client_id' => $this->api->getOptions()->getApiKey(),
            'client_secret' => $this->api->getOptions()->getApiSecret(),
            'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'subject_token' => $token,
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
            'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
        ];
        if (Util::getShopifyConfig('expiring_offline_tokens', $shop)) {
            $data['expiring'] = 1;
        }

        return $this->oauthAccessTokenPost($data);
    }

    /**
     * {@inheritdoc}
     *
     * @codeCoverageIgnore No need to retest.
     */
    public function getAccessData(string $code, ?AuthMode $grantMode = null): ResponseAccess
    {
        $grantMode = $grantMode ?? AuthMode::OFFLINE();
        $shop = $this->getShopDomain($this->api->getSession())->toNative();
        $useExpiringOffline = Util::getShopifyConfig('expiring_offline_tokens', $shop)
            && $grantMode->isSame(AuthMode::OFFLINE());

        if ($useExpiringOffline) {
            return $this->oauthAccessTokenPost([
                'client_id' => $this->api->getOptions()->getApiKey(),
                'client_secret' => $this->api->getOptions()->getApiSecret(),
                'code' => $code,
                'expiring' => 1,
            ]);
        }

        return $this->api->requestAccess($code);
    }

    /**
     * {@inheritdoc}
     */
    public function refreshOfflineAccessToken(string $refreshToken): ResponseAccess
    {
        return $this->oauthAccessTokenPost([
            'client_id' => $this->api->getOptions()->getApiKey(),
            'client_secret' => $this->api->getOptions()->getApiSecret(),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function exchangeNonExpiringOfflineTokenForExpiring(
        string $shopDomain,
        string $nonExpiringOfflineAccessToken
    ): ResponseAccess {
        $this->make(new Session($shopDomain, $nonExpiringOfflineAccessToken));

        return $this->oauthAccessTokenPost([
            'client_id' => $this->api->getOptions()->getApiKey(),
            'client_secret' => $this->api->getOptions()->getApiSecret(),
            'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'subject_token' => $nonExpiringOfflineAccessToken,
            'subject_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
            'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
            'expiring' => 1,
        ]);
    }

    /**
     * POST /admin/oauth/access_token (JSON body).
     *
     * @param array $json
     *
     * @return ResponseAccess
     */
    protected function oauthAccessTokenPost(array $json): ResponseAccess
    {
        $response = $this->api->request(
            'POST',
            '/admin/oauth/access_token',
            [
                'json' => $json,
            ]
        );

        if (isset($response['errors']) && $response['errors'] === true) {
            throw new ApiException(
                is_string($response['body']) ? $response['body'] : 'Unknown error',
                0,
                $response['exception'] ?? null
            );
        }

        return $response['body'];
    }

    /**
     * {@inheritdoc}
     * TODO: Convert to GraphQL.
     */
    public function getScriptTags(array $params = []): ResponseAccess
    {
        // Setup the params
        $reqParams = array_merge(
            [
                'limit' => 250,
                'fields' => 'id,src',
            ],
            $params
        );

        // Fire the request
        $response = $this->doRequest(
            ApiMethod::GET(),
            '/admin/script_tags.json',
            $reqParams
        );

        return $response['body']['script_tags'];
    }

    /**
     * {@inheritdoc}
     * TODO: Convert to GraphQL.
     */
    public function createScriptTag(array $payload): ResponseAccess
    {
        // Fire the request
        $response = $this->doRequest(
            ApiMethod::POST(),
            '/admin/script_tags.json',
            ['script_tag' => $payload]
        );

        return $response['body'];
    }

    /**
     * {@inheritdoc}
     * TODO: Convert to GraphQL.
     */
    public function deleteScriptTag(int $scriptTagId): ResponseAccess
    {
        // Fire the request
        $response = $this->doRequest(
            ApiMethod::DELETE(),
            "/admin/script_tags/{$scriptTagId}.json"
        );

        return $response['body'];
    }

    /**
     * {@inheritdoc}
     *
     * A subscription is read through the GraphQL Admin API and comes back with the keys the REST
     * Admin API used, so existing callers keep working. GraphQL has no activation, trial end or
     * cancellation date for it, so those keys are null.
     *
     * Other charges are still read through the REST Admin API: GraphQL has no return URL for a
     * one-time purchase, and ActivatePlan binds a charge to its plan through that URL.
     *
     * @throws Exception
     */
    public function getCharge(ChargeType $chargeType, ChargeReference $chargeRef): ResponseAccess
    {
        if ($chargeType->isSame(ChargeType::RECURRING())) {
            return new ResponseAccess($this->chargeFromNode($this->getSubscriptionNode($chargeRef)));
        }

        // API path
        $typeString = $this->chargeApiPath($chargeType);

        // Fire the request
        $response = $this->doRequest(
            ApiMethod::GET(),
            "/admin/{$typeString}s/{$chargeRef->toNative()}.json"
        );

        return $response['body'][$typeString];
    }

    /**
     * {@inheritdoc}
     *
     * Shopify activates a charge as soon as the merchant approves it (API version 2021-01 and later),
     * and the GraphQL Admin API has no activation mutation, so this only reads the charge back.
     *
     * @throws Exception
     */
    public function activateCharge(ChargeType $chargeType, ChargeReference $chargeRef): ResponseAccess
    {
        return $this->getCharge($chargeType, $chargeRef);
    }

    /**
     * {@inheritdoc}
     *
     * Recurring charges use appSubscriptionCreate and one-time charges use appPurchaseOneTimeCreate.
     * The result has the keys the REST Admin API returned: the charge details sent, plus `id`,
     * `status` and `confirmation_url`.
     *
     * @throws Exception
     */
    public function createCharge(ChargeType $chargeType, PlanDetailsTransfer $payload): ResponseAccess
    {
        if ($chargeType->isSame(ChargeType::RECURRING())) {
            $lineItems = [$this->recurringLineItem($payload)];
            // Shopify bills usage only on 30-day subscriptions
            if ($payload->cappedAmount > 0 && $payload->interval !== ChargeInterval::ANNUAL()->toNative()) {
                $lineItems[] = [
                    'plan' => [
                        'appUsagePricingDetails' => [
                            'cappedAmount' => $this->money($payload->cappedAmount),
                            'terms' => (string) $payload->terms,
                        ],
                    ],
                ];
            }

            $mutation = 'appSubscriptionCreate';
            $chargeField = 'appSubscription';
            $response = $this->requestAppSubscriptionCreate($payload, $lineItems);
        } elseif ($chargeType->isSame(ChargeType::CHARGE())) {
            $query = '
            mutation appPurchaseOneTimeCreate(
                $name: String!,
                $price: MoneyInput!,
                $returnUrl: URL!,
                $test: Boolean
            ) {
                appPurchaseOneTimeCreate(
                    name: $name,
                    price: $price,
                    returnUrl: $returnUrl,
                    test: $test
                ) {
                    appPurchaseOneTime {
                        id
                        status
                    }
                    confirmationUrl
                    userErrors {
                        field
                        message
                    }
                }
            }
            ';

            $mutation = 'appPurchaseOneTimeCreate';
            $chargeField = 'appPurchaseOneTime';
            $response = $this->doRequestGraphQL($query, [
                'name' => $payload->name,
                'price' => $this->money($payload->price),
                'returnUrl' => $payload->returnUrl,
                'test' => $payload->test,
            ]);
        } else {
            throw new ApiException('Only recurring and one-time charges can be created.');
        }

        $this->throwOnUserErrors($response['body'], $mutation);

        return new ResponseAccess(array_merge($payload->toArray(), [
            'id' => $this->idFromGid((string) data_get($response['body'], "data.{$mutation}.{$chargeField}.id")),
            'status' => strtolower((string) data_get($response['body'], "data.{$mutation}.{$chargeField}.status")),
            'confirmation_url' => data_get($response['body'], "data.{$mutation}.confirmationUrl"),
        ]));
    }

    /**
     * {@inheritdoc}
     *
     * @deprecated Use createCharge(), which creates every plan's charge and throws when Shopify refuses one.
     *
     * @throws Exception
     */
    public function createChargeGraphQL(PlanDetailsTransfer $payload): ResponseAccess
    {
        $response = $this->requestAppSubscriptionCreate($payload, [$this->recurringLineItem($payload)]);

        return $response['body']['data']['appSubscriptionCreate'];
    }

    /**
     * {@inheritdoc}
     *
     * @throws Exception
     */
    public function getWebhooks(array $params = []): ResponseAccess
    {
        $query = '
        query webhookSubscriptions($first: Int!) {
            webhookSubscriptions(first: $first) {
                edges {
                    node {
                        id
                        topic
                        endpoint {
                            ...on WebhookHttpEndpoint {
                                callbackUrl
                            }
                        }
                    }
                }
            }
        }
        ';

        $variables = [
            'first' => 250,
        ];

        $response = $this->doRequestGraphQL($query, $variables);

        return $response['body'];
    }

    /**
     * {@inheritdoc}
     *
     * @throws Exception
     */
    public function createWebhook(array $payload): ResponseAccess
    {
        $query = '
        mutation webhookSubscriptionCreate(
            $topic: WebhookSubscriptionTopic!,
            $webhookSubscription: WebhookSubscriptionInput!
        ) {
            webhookSubscriptionCreate(
                topic: $topic
                webhookSubscription: $webhookSubscription
            ) {
                userErrors {
                    field
                    message
                }
                webhookSubscription {
                    id
                    topic
                }
            }
        }
        ';

        // Change REST-format topics ("resource/event")
        // to GraphQL-format topics ("RESOURCE_EVENT"), for pre-v17 compatibility
        $topic = Util::getGraphQLWebhookTopic($payload['topic']);
        $variables = [
            'topic' => $topic,
            'webhookSubscription' => [
                'callbackUrl' => $payload['address'],
                'format' => 'JSON',
            ],
        ];

        $response = $this->doRequestGraphQL($query, $variables);
        $this->throwOnUserErrors($response['body'], 'webhookSubscriptionCreate');

        return $response['body'];
    }

    /**
     * {@inheritdoc}
     *
     * @throws Exception
     */
    public function deleteWebhook(string $webhookId): ResponseAccess
    {
        $query = '
        mutation webhookSubscriptionDelete($id: ID!) {
            webhookSubscriptionDelete(id: $id) {
                userErrors {
                    field
                    message
                }
                deletedWebhookSubscriptionId
            }
        }
        ';

        $variables = [
            'id' => $webhookId,
        ];

        $response = $this->doRequestGraphQL($query, $variables);
        // A subscription that no longer exists is what the delete was for: it can already be
        // gone when two installers run at once or when it is removed after getWebhooks().
        $this->throwOnUserErrors(
            $response['body'],
            'webhookSubscriptionDelete',
            static fn (string $message): bool => stripos($message, 'does not exist') !== false
        );

        return $response['body'];
    }

    /**
     * {@inheritdoc}
     *
     * A usage record belongs to the subscription's usage line item, so the subscription is read first.
     *
     * @throws Exception
     */
    public function createUsageCharge(UsageChargeDetailsTransfer $payload)
    {
        $subscription = $this->getSubscriptionNode($payload->chargeReference);
        $usageLineItem = Arr::first(
            (array) ($subscription['lineItems'] ?? []),
            static fn ($lineItem): bool => data_get($lineItem, 'plan.pricingDetails.__typename') === 'AppUsagePricing'
        );
        if ($usageLineItem === null) {
            throw new ApiException('The subscription has no usage pricing to charge against.');
        }

        $query = '
        mutation appUsageRecordCreate(
            $subscriptionLineItemId: ID!,
            $price: MoneyInput!,
            $description: String!
        ) {
            appUsageRecordCreate(
                subscriptionLineItemId: $subscriptionLineItemId,
                price: $price,
                description: $description
            ) {
                appUsageRecord {
                    id
                    createdAt
                    subscriptionLineItem {
                        plan {
                            pricingDetails {
                                ... on AppUsagePricing {
                                    balanceUsed {
                                        amount
                                    }
                                    cappedAmount {
                                        amount
                                    }
                                }
                            }
                        }
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }
        ';

        $response = $this->doRequestGraphQL($query, [
            'subscriptionLineItemId' => $usageLineItem['id'],
            'price' => $this->money(
                $payload->price,
                data_get($usageLineItem, 'plan.pricingDetails.cappedAmount.currencyCode', 'USD')
            ),
            'description' => $payload->description,
        ]);
        $this->throwOnUserErrors($response['body'], 'appUsageRecordCreate');

        $record = data_get($response['body'], 'data.appUsageRecordCreate.appUsageRecord');
        if (data_get($record, 'id') === null) {
            return false;
        }

        // The usage balance after this record, as the REST Admin API returned it
        $usage = data_get($record, 'subscriptionLineItem.plan.pricingDetails');
        $balanceUsed = data_get($usage, 'balanceUsed.amount');
        $cappedAmount = data_get($usage, 'cappedAmount.amount');

        return new ResponseAccess([
            'id' => $this->idFromGid((string) data_get($record, 'id')),
            'description' => $payload->description,
            'price' => $payload->price,
            'created_at' => data_get($record, 'createdAt'),
            'balance_used' => is_numeric($balanceUsed) ? (float) $balanceUsed : null,
            'balance_remaining' => is_numeric($balanceUsed) && is_numeric($cappedAmount)
                ? round((float) $cappedAmount - (float) $balanceUsed, 2)
                : null,
        ]);
    }

    /**
     * Read a subscription through the GraphQL Admin API.
     *
     * Shopify returns the node only to the app and store it belongs to.
     *
     * @param ChargeReference $chargeRef The subscription's charge ID.
     *
     * @throws Exception
     *
     * @return array
     */
    protected function getSubscriptionNode(ChargeReference $chargeRef): array
    {
        $query = '
        query appSubscription($id: ID!) {
            node(id: $id) {
                __typename
                id
                ... on AppSubscription {
                    name
                    returnUrl
                    status
                    test
                    trialDays
                    createdAt
                    currentPeriodEnd
                    lineItems {
                        id
                        plan {
                            pricingDetails {
                                __typename
                                ... on AppRecurringPricing {
                                    interval
                                    price {
                                        amount
                                        currencyCode
                                    }
                                }
                                ... on AppUsagePricing {
                                    terms
                                    cappedAmount {
                                        amount
                                        currencyCode
                                    }
                                    balanceUsed {
                                        amount
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        ';

        $id = "gid://shopify/AppSubscription/{$chargeRef->toNative()}";
        $response = $this->doRequestGraphQL($query, ['id' => $id]);
        $node = data_get($response['body'], 'data.node');
        if ($node === null || data_get($node, '__typename') !== 'AppSubscription') {
            throw new ApiException("Charge {$id} was not found.");
        }

        return $node instanceof ResponseAccess ? $node->toArray() : (array) $node;
    }

    /**
     * Turn a subscription node into the keys the REST Admin API used for the same charge.
     *
     * @param array $node The node from getSubscriptionNode().
     *
     * @return array
     */
    protected function chargeFromNode(array $node): array
    {
        $charge = [
            'id' => $this->idFromGid((string) ($node['id'] ?? '')),
            'name' => $node['name'] ?? null,
            'status' => strtolower((string) ($node['status'] ?? '')),
            'test' => $node['test'] ?? null,
            'trial_days' => $node['trialDays'] ?? null,
            'return_url' => $node['returnUrl'] ?? null,
            'created_at' => $node['createdAt'] ?? null,
            'billing_on' => $node['currentPeriodEnd'] ?? null,
            // Not in the GraphQL Admin API
            'activated_on' => null,
            'trial_ends_on' => null,
            'cancelled_on' => null,
        ];

        foreach ((array) ($node['lineItems'] ?? []) as $lineItem) {
            $pricing = data_get($lineItem, 'plan.pricingDetails', []);
            if (data_get($pricing, '__typename') === 'AppRecurringPricing') {
                $charge['price'] = data_get($pricing, 'price.amount');
                $charge['currency'] = data_get($pricing, 'price.currencyCode');
            } elseif (data_get($pricing, '__typename') === 'AppUsagePricing') {
                $cappedAmount = data_get($pricing, 'cappedAmount.amount');
                $balanceUsed = data_get($pricing, 'balanceUsed.amount');
                $charge['capped_amount'] = $cappedAmount;
                $charge['terms'] = data_get($pricing, 'terms');
                $charge['balance_used'] = is_numeric($balanceUsed) ? (float) $balanceUsed : null;
                $charge['balance_remaining'] = is_numeric($balanceUsed) && is_numeric($cappedAmount)
                    ? round((float) $cappedAmount - (float) $balanceUsed, 2)
                    : null;
            }
        }

        return $charge;
    }

    /**
     * Send appSubscriptionCreate for a plan.
     *
     * @param PlanDetailsTransfer $payload   The plan details.
     * @param array               $lineItems The subscription's line items.
     *
     * @throws Exception
     *
     * @return array
     */
    protected function requestAppSubscriptionCreate(PlanDetailsTransfer $payload, array $lineItems): array
    {
        $query = '
        mutation appSubscriptionCreate(
            $name: String!,
            $returnUrl: URL!,
            $trialDays: Int,
            $test: Boolean,
            $lineItems: [AppSubscriptionLineItemInput!]!
        ) {
            appSubscriptionCreate(
                name: $name,
                returnUrl: $returnUrl,
                trialDays: $trialDays,
                test: $test,
                lineItems: $lineItems
            ) {
                appSubscription {
                    id
                    status
                }
                confirmationUrl
                userErrors {
                    field
                    message
                }
            }
        }
        ';

        return $this->doRequestGraphQL($query, [
            'name' => $payload->name,
            'returnUrl' => $payload->returnUrl,
            'trialDays' => $payload->trialDays,
            'test' => $payload->test,
            'lineItems' => $lineItems,
        ]);
    }

    /**
     * The recurring line item of a plan's subscription.
     *
     * @param PlanDetailsTransfer $payload The plan details.
     *
     * @return array
     */
    protected function recurringLineItem(PlanDetailsTransfer $payload): array
    {
        return [
            'plan' => [
                'appRecurringPricingDetails' => [
                    'price' => $this->money($payload->price),
                    'interval' => $payload->interval ?? ChargeInterval::EVERY_30_DAYS()->toNative(),
                ],
            ],
        ];
    }

    /**
     * A MoneyInput value.
     *
     * @param mixed  $amount       The amount.
     * @param string $currencyCode The currency, USD unless the charge already has one.
     *
     * @return array
     */
    protected function money($amount, string $currencyCode = 'USD'): array
    {
        return [
            'amount' => $amount,
            'currencyCode' => $currencyCode,
        ];
    }

    /**
     * Converts ChargeType enum into an API path.
     *
     * @param ChargeType $chargeType The charge type.
     *
     * @return string
     */
    protected function chargeApiPath(ChargeType $chargeType): string
    {
        // Convert to API path
        if ($chargeType->isSame(ChargeType::RECURRING())) {
            $format = '%s_application_charge';
        } elseif ($chargeType->isSame(ChargeType::CHARGE())) {
            $format = 'application_charge';
        } else {
            $format = 'application_%s';
        }

        return sprintf($format, strtolower($chargeType->toNative()));
    }

    /**
     * The numeric ID at the end of a GraphQL ID, which is the ID the REST Admin API used.
     *
     * @param string $gid The GraphQL ID, for example gid://shopify/AppSubscription/1029266947.
     *
     * @return int
     */
    protected function idFromGid(string $gid): int
    {
        return (int) Str::afterLast(Str::before($gid, '?'), '/');
    }

    /**
     * Fire the request using the API instance.
     *
     * @param ApiMethod $method  The HTTP method.
     * @param string    $path    The endpoint path.
     * @param ?array    $payload The optional payload to send to the endpoint.
     *
     * @throws RequestException
     *
     * @return array
     */
    protected function doRequest(ApiMethod $method, string $path, ?array $payload = null)
    {
        $response = $this->api->rest($method->toNative(), $path, $payload);
        if ($response['errors'] === true) {
            // Request error somewhere, throw the exception
            throw new ApiException(
                is_string($response['body']) ? $response['body'] : 'Unknown error',
                0,
                $response['exception']
            );
        }

        return $response;
    }

    /**
     * Throw when Shopify refused a mutation through its `userErrors`.
     *
     * Such a refusal comes back as HTTP 200 without top-level `errors`, so `doRequestGraphQL()`
     * does not see it: without this check a refused webhook subscription (an invalid address,
     * a topic the app has no scope for) looks exactly like a created one.
     *
     * @param ResponseAccess $body     The response body.
     * @param string         $mutation The mutation's field in `data`.
     * @param callable|null  $ignore   Returns true for an error message that is not a failure.
     *
     * @throws ApiException
     *
     * @return void
     */
    protected function throwOnUserErrors(ResponseAccess $body, string $mutation, ?callable $ignore = null): void
    {
        $userErrors = data_get($body, "data.{$mutation}.userErrors", []);
        if ($userErrors instanceof ResponseAccess) {
            $userErrors = $userErrors->toArray();
        }

        if ($ignore !== null) {
            $userErrors = array_filter(
                (array) $userErrors,
                static fn ($error): bool => ! $ignore((string) data_get($error, 'message', ''))
            );
        }

        if (empty($userErrors)) {
            return;
        }

        $messages = array_map(
            static function ($error): string {
                $field = implode('.', (array) data_get($error, 'field', []));
                $message = (string) data_get($error, 'message', 'Unknown error');

                return $field === '' ? $message : "{$field}: {$message}";
            },
            (array) $userErrors
        );

        throw new ApiException("{$mutation} failed: ".implode('; ', $messages));
    }

    /**
     * Fire the request using the GraphQL API Instance.
     *
     * @param string $query   The query of GraphQL
     * @param array  $payload The option payload to using on the query
     *
     * @throws Exception
     *
     * @return array
     */
    protected function doRequestGraphQL(string $query, array $payload = []): array
    {
        $response = $this->api->graph($query, $payload);
        if ($response['errors'] !== false) {
            // A failed request (network or HTTP status) sets `errors` to true and puts Shopify's errors in the body
            $errors = $response['errors'] === true ? ($response['body'] ?? null) : $response['errors'];
            $previous = ($response['exception'] ?? null) instanceof Throwable ? $response['exception'] : null;

            // Request error somewhere, throw the exception
            throw new ApiException($this->graphErrorMessage($errors, $previous), 0, $previous);
        }

        return $response;
    }

    /**
     * The first error message from a failed GraphQL request.
     *
     * @param mixed          $errors   Shopify's errors: a list of errors, a message, or nothing.
     * @param Throwable|null $previous The request's exception, if any.
     *
     * @return string
     */
    protected function graphErrorMessage($errors, ?Throwable $previous): string
    {
        if ($errors instanceof ResponseAccess) {
            $errors = $errors->toArray();
        }

        if (is_array($errors) && isset($errors[0]['message']) && is_string($errors[0]['message'])) {
            return $errors[0]['message'];
        }

        if (is_string($errors) && $errors !== '') {
            return $errors;
        }

        return $previous !== null && $previous->getMessage() !== '' ? $previous->getMessage() : 'Unknown error';
    }

    /**
     * Find shop from session or request.
     *
     * @param Session|null $session
     *
     * @return NullableShopDomain
     */
    private function getShopDomain(?Session $session = null): NullableShopDomain
    {
        // Check for existing session passed in
        if ($session && $session->getShop()) {
            return NullableShopDomain::fromNative($session->getShop());
        }

        $options = [
            // Input
            DataSource::INPUT()->toNative() => function (): ?string {
                return Arr::get(Request::all(), 'shop');
            },
            // Headers
            DataSource::HEADER()->toNative() => function (): ?string {
                return Request::header('X-Shop-Domain');
            },
            // Headers: Referer
            DataSource::REFERER()->toNative() => function (): ?string {
                $url = parse_url(Request::server('HTTP_REFERER', ''), PHP_URL_QUERY);
                parse_str($url ?? '', $refererQueryParams);

                return Arr::get($refererQueryParams, 'shop');
            },
        ];
        foreach ($options as $fn) {
            $result = $fn();
            if ($result !== null) {
                return NullableShopDomain::fromNative($result);
            }
        }

        return NullableShopDomain::fromNative(null);
    }
}
