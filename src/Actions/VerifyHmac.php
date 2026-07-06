<?php

declare(strict_types=1);

namespace Osiset\ShopifyApp\Actions;

use Illuminate\Http\Request;
use Osiset\ShopifyApp\Contracts\ApiHelper as IApiHelper;
use Osiset\ShopifyApp\Objects\Enums\DataSource;

final class VerifyHmac
{
    public function __construct(private IApiHelper $apiHelper)
    {
        $this->apiHelper->make();
    }

    public function handle(Request $request): ?bool
    {
        $hmac = $this->getHmacFromRequest($request);

        if ($hmac['source'] === null) {
            return null;
        }

        $data = $this->getRequestData($request, $hmac['source']);

        return $this->apiHelper->verifyRequest($data);
    }

    private function getHmacFromRequest(Request $request): array
    {
        $options = [
            DataSource::INPUT()->toNative() => $request->input('hmac'),
            DataSource::HEADER()->toNative() => $request->header('X-Shop-Signature'),
            DataSource::REFERER()->toNative() => function () use ($request): ?string {
                $url = parse_url($request->header('referer', ''), PHP_URL_QUERY);
                parse_str($url ?? '', $refererQueryParams);
                if (! $refererQueryParams || ! isset($refererQueryParams['hmac'])) {
                    return null;
                }

                return $refererQueryParams['hmac'];
            },
        ];

        foreach ($options as $method => $value) {
            $result = is_callable($value) ? $value() : $value;
            if ($result !== null) {
                return ['source' => $method, 'value' => $value];
            }
        }

        return ['source' => null, 'value' => null];
    }

    private function getRequestData(Request $request, string $source): array
    {
        $options = [
            DataSource::INPUT()->toNative() => function () use ($request): array {
                $verify = [];
                foreach ($request->query() as $key => $value) {
                    $verify[$key] = $this->parseDataSourceValue($value);
                }

                return $verify;
            },
            DataSource::HEADER()->toNative() => function () use ($request): array {
                $shop = $request->header('X-Shop-Domain');
                $signature = $request->header('X-Shop-Signature');
                $timestamp = $request->header('X-Shop-Time');

                $verify = [
                    'shop' => $shop,
                    'hmac' => $signature,
                    'timestamp' => $timestamp,
                ];

                $code = $request->header('X-Shop-Code') ?? null;
                $locale = $request->header('X-Shop-Locale') ?? null;
                $state = $request->header('X-Shop-State') ?? null;
                $id = $request->header('X-Shop-ID') ?? null;
                $ids = $request->header('X-Shop-IDs') ?? null;

                foreach (compact('code', 'locale', 'state', 'id', 'ids') as $key => $value) {
                    if ($value) {
                        $verify[$key] = $this->parseDataSourceValue($value);
                    }
                }

                return $verify;
            },
            DataSource::REFERER()->toNative() => function () use ($request): array {
                $url = parse_url($request->header('referer'), PHP_URL_QUERY);
                parse_str($url, $refererQueryParams);

                $verify = [];
                foreach ($refererQueryParams as $key => $value) {
                    $verify[$key] = $this->parseDataSourceValue($value);
                }

                return $verify;
            },
        ];

        return $options[$source]();
    }

    private function parseDataSourceValue(mixed $value): string
    {
        $formatValue = function (mixed $val): string {
            return is_array($val) ? '["'.implode('", "', $val).'"]' : $val;
        };

        if (is_array($value) && is_array(current($value))) {
            return implode(', ', array_map($formatValue, $value));
        }

        return $formatValue($value);
    }
}
