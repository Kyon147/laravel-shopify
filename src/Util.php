<?php

namespace Osiset\ShopifyApp;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use LogicException;
use Osiset\ShopifyApp\Objects\Values\Hmac;

/**
 * Utilities and helpers used in various parts of the package.
 */
class Util
{
    /**
     * HMAC creation helper.
     *
     * @param array  $opts   The options for building the HMAC.
     * @param string $secret The app secret key.
     *
     * @return Hmac
     */
    public static function createHmac(array $opts, string $secret): Hmac
    {
        // Setup defaults
        $data = $opts['data'];
        $raw = $opts['raw'] ?? false;
        $buildQuery = $opts['buildQuery'] ?? false;
        $buildQueryWithJoin = $opts['buildQueryWithJoin'] ?? false;
        $encode = $opts['encode'] ?? false;

        if ($buildQuery) {
            //Query params must be sorted and compiled
            ksort($data);
            $queryCompiled = [];
            foreach ($data as $key => $value) {
                $queryCompiled[] = "{$key}=".(is_array($value) ? implode(',', $value) : $value);
            }
            $data = implode(
                $buildQueryWithJoin ? '&' : '',
                $queryCompiled
            );
        }

        // Create the hmac all based on the secret
        $hmac = hash_hmac('sha256', $data, $secret, $raw);

        // Return based on options
        $result = $encode ? base64_encode($hmac) : $hmac;

        return Hmac::fromNative($result);
    }

    /**
     * Parse query strings the same way as Rack::Until in Ruby. (This is a port from Rack 2.3.0.).
     *
     * From Shopify docs, they use Rack::Util.parse_query, which does *not* parse array parameters properly.
     * Array parameters such as `name[]=value1&name[]=value2` becomes `['name[]' => ['value1', 'value2']] in Shopify.
     * See: https://github.com/rack/rack/blob/f9ad97fd69a6b3616d0a99e6bedcfb9de2f81f6c/lib/rack/query_parser.rb#L36
     *
     * @param string $queryString The query string.
     * @param string|null $delimiter  The delimiter.
     *
     * @return mixed
     */
    public static function parseQueryString(string $queryString, string $delimiter = null): array
    {
        $commonSeparator = [';' => '/[;]\s*/', ';,' => '/[;,]\s*/', '&' => '/[&]\s*/'];
        $defaultSeparator = '/[&;]\s*/';

        $params = [];
        $split = preg_split(
            $delimiter ? $commonSeparator[$delimiter] || '/['.$delimiter.']\s*/' : $defaultSeparator,
            $queryString ?? ''
        );

        foreach ($split as $part) {
            if (! $part) {
                continue;
            }

            [$key, $value] = strpos($part, '=') !== false ? explode('=', $part, 2) : [$part, null];

            $key = urldecode($key);
            $value = $value !== null ? urldecode($value) : $value;

            if (isset($params[$key])) {
                $cur = $params[$key];

                if (is_array($cur)) {
                    $params[$key][] = $value;
                } else {
                    $params[$key] = [$cur, $value];
                }
            } else {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * URL-safe Base64 encoding.
     *
     * Replaces `+` with `-` and `/` with `_` and trims padding `=`.
     *
     * @param string $data The data to be encoded.
     *
     * @return string
     */
    public static function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * URL-safe Base64 decoding.
     *
     * Replaces `-` with `+` and `_` with `/`.
     *
     * Adds padding `=` if needed.
     *
     * @param string $data The data to be decoded.
     *
     * @return string
     */
    public static function base64UrlDecode($data)
    {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }

    /**
     * Checks if the route should be registered or not.
     *
     * @param string     $routeToCheck The route name to check.
     * @param bool|array $routesToExclude The routes which are to be excluded.
     *
     * @return bool
     */
    public static function registerPackageRoute(string $routeToCheck, $routesToExclude): bool
    {
        if ($routesToExclude === false) {
            return true;
        }

        if (is_array($routesToExclude) === false) {
            throw new LogicException('Excluded routes must be an array');
        }

        return in_array($routeToCheck, $routesToExclude, true) === false;
    }

    /**
     * Get the config value for a key.
     * Used as a helper function so it is accessible in Blade.
     * The second param of `shop` is important for `config_api_callback`.
     *
     * @param string $key  The key to lookup.
     * @param mixed  $shop The shop domain (string, ShopDomain, etc).
     *
     * @return mixed
     */
    public static function getShopifyConfig(string $key, $shop = null)
    {
        $config = Config::get('shopify-app', []);

        $config['user_model'] = Config::get("auth.providers.{$config['shop_auth_provider']}.model", Config::get('auth.providers.users.model'));


        if (Str::is('route_names.*', $key)) {
            // scope the Arr::get() call to the "route_names" array
            // to allow for dot-notation keys like "authenticate.oauth"
            // this is necessary because Arr::get() only finds dot-notation keys
            // if they are at the top level of the given array
            return Arr::get(
                $config['route_names'],
                Str::after($key, '.')
            );
        }

        // Check if config API callback is defined
        if (Str::startsWith($key, 'api')
            && Arr::exists($config, 'config_api_callback')
            && is_callable($config['config_api_callback'])) {
            // It is, use this to get the config value
            return call_user_func(
                Arr::get($config, 'config_api_callback'),
                $key,
                $shop
            );
        }

        return Arr::get($config, $key);
    }

    /**
     * Convert a REST-format webhook topic ("resource/event")
     * to a GraphQL-format webhook topic ("RESOURCE_EVENT").
     *
     * @param string $topic
     *
     * @return string
     */
    public static function getGraphQLWebhookTopic(string $topic): string
    {
        return Str::of($topic)
                  ->upper()
                  ->replaceMatches('/[^A-Z_]/', '_');
    }


    /**
     * Get the table name for shop
     *
     * @return string
     */
    public static function getShopsTable(): string
    {
        return self::getShopifyConfig('table_names.shops') ?? 'users';
    }

    /**
     * Get the table foreign key for shop
     *
     * @return string
     */
    public static function getShopsTableForeignKey(): string
    {
        return Str::singular(self::getShopsTable()).'_id';
    }
}
