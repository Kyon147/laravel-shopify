<?php

namespace Osiset\ShopifyApp\Services;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Osiset\ShopifyApp\Data\ApplicationOnlyAccessCredentials;
use Osiset\ShopifyApp\Util;
use RuntimeException;
use Throwable;

/**
 * @see https://shopify.dev/docs/apps/build/authentication-authorization/application-only-access-tokens
 */
class ApplicationOnlyAccessTokenClient
{
    private const CLIENT_ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    private const ASSERTION_LIFETIME_SECONDS = 60;

    private const MAX_ATTEMPTS = 3;

    private const REQUEST_TIMEOUT_SECONDS = 15;

    /**
     * @return array{access_token: string, scope: string|null, expires_in: int}
     */
    public function requestAccessToken(
        string $shopDomain,
        ?ApplicationOnlyAccessCredentials $credentials = null,
    ): array {
        $this->assertShopDomain($shopDomain);

        $clientId = (string) Util::getShopifyConfig('api_key');
        $keyId = $credentials?->keyId ?? $this->resolveKeyIdFromConfig();
        $privateKeyPem = $credentials?->privateKeyPem ?? $this->resolvePrivateKeyPemFromConfig();

        if ($clientId === '' || $privateKeyPem === '') {
            throw new RuntimeException(
                'Application-only private key is not configured (see shopify-app.application_only).',
            );
        }

        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return $this->sendTokenRequest($shopDomain, $clientId, $keyId, $privateKeyPem);
            } catch (RequestException $exception) {
                $lastException = $exception;
                $status = $exception->response?->status();

                if ($status !== null && $status >= 400 && $status < 500) {
                    throw $exception;
                }

                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $exception;
                }
            } catch (Throwable $exception) {
                $lastException = $exception;

                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $exception;
                }
            }

            usleep((2 ** ($attempt - 1)) * 1_000_000);
        }

        if ($lastException === null) {
            throw new RuntimeException('Application-only token request failed.');
        }

        throw $lastException;
    }

    /**
     * @return array{access_token: string, scope: string|null, expires_in: int}
     */
    private function sendTokenRequest(
        string $shopDomain,
        string $clientId,
        string $keyId,
        string $privateKeyPem,
    ): array {
        $clientAssertion = $this->createClientAssertion($shopDomain, $clientId, $keyId, $privateKeyPem);

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->post("https://{$shopDomain}/admin/oauth/access_token", [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_assertion_type' => self::CLIENT_ASSERTION_TYPE,
                'client_assertion' => $clientAssertion,
            ])
            ->throw();

        /** @var array<string, mixed> $payload */
        $payload = $response->json();

        if (is_string($payload['access_token'] ?? null) === false) {
            throw new RuntimeException('Shopify returned an application-only token response without access_token.');
        }

        $expiresIn = $payload['expires_in'] ?? null;

        if (is_int($expiresIn) === false && is_numeric($expiresIn) === false) {
            throw new RuntimeException('Shopify returned an application-only token response without expires_in.');
        }

        return [
            'access_token' => $payload['access_token'],
            'scope' => is_string($payload['scope'] ?? null) ? $payload['scope'] : null,
            'expires_in' => (int) $expiresIn,
        ];
    }

    private function createClientAssertion(
        string $shopDomain,
        string $clientId,
        string $keyId,
        string $privateKeyPem,
    ): string {
        $now = time();

        $payload = [
            'iss' => $clientId,
            'sub' => $clientId,
            'aud' => "https://{$shopDomain}/admin/oauth/access_token",
            'iat' => $now,
            'exp' => $now + self::ASSERTION_LIFETIME_SECONDS,
            'jti' => (string) Str::uuid(),
        ];

        $algorithm = $this->resolveSigningAlgorithm($privateKeyPem);

        return JWT::encode($payload, $privateKeyPem, $algorithm, $keyId);
    }

    private function resolveSigningAlgorithm(string $privateKeyPem): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);

        if ($key === false) {
            throw new RuntimeException('Invalid private key PEM.');
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false) {
            throw new RuntimeException('Could not read private key details.');
        }

        $type = $details['type'] ?? null;

        if ($type === OPENSSL_KEYTYPE_RSA) {
            return 'RS256';
        }

        if ($type === OPENSSL_KEYTYPE_EC) {
            $curve = $details['ec']['curve_name'] ?? '';

            if ($curve === 'prime256v1') {
                return 'ES256';
            }
        }

        throw new RuntimeException('Use an RSA or P-256 EC private key.');
    }

    private function resolveKeyIdFromConfig(): string
    {
        $keyId = trim((string) Util::getShopifyConfig('application_only.key_id'));

        if ($keyId === '') {
            return 'app-only-1';
        }

        return $keyId;
    }

    private function resolvePrivateKeyPemFromConfig(): string
    {
        $base64 = trim((string) Util::getShopifyConfig('application_only.private_key_b64'));

        if ($base64 !== '') {
            $decoded = base64_decode($base64, true);

            if (is_string($decoded) === true && $decoded !== '') {
                return $decoded;
            }
        }

        $path = trim((string) Util::getShopifyConfig('application_only.private_key_path'));

        if ($path !== '' && is_readable($path) === true) {
            $contents = file_get_contents($path);

            if (is_string($contents) === true && $contents !== '') {
                return $contents;
            }
        }

        return '';
    }

    private function assertShopDomain(string $shopDomain): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shopDomain) !== 1) {
            throw new InvalidArgumentException(
                'Use the shop\'s exact, lowercase permanent myshopify.com domain.',
            );
        }
    }
}
