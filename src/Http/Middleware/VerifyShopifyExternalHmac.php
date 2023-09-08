<?php

namespace Osiset\ShopifyApp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Osiset\ShopifyApp\Exceptions\SignatureVerificationException;

/**
 * Response for ensuring an authenticated request.
 */
class VerifyShopifyExternalHmac extends VerifyShopifyExternal
{
    /**
     * Handle an incoming request.
     * If HMAC is present, it will try to validate it.
     * If shop is not logged in, redirect to authenticate will happen.
     *
     * @param Request $request The request object.
     * @param \Closure $next The next action.
     *
     * @return mixed
     * @throws \Exception
     * @throws SignatureVerificationException
     */
    public function handle(Request $request, Closure $next)
    {
        // Verify the HMAC (if available)
        $hmacResult = $this->verifyHmac($request);
        if ($hmacResult === false) {
            // Invalid HMAC
            throw new SignatureVerificationException('Unable to verify hmac.');
        }

        $timestamp = $request->query('timestamp');
        if (!$timestamp) {
            throw new \Exception(__('Timestamp does not specified.'));
        }

        if ($timestamp < time() - 86400) {
            throw new \Exception(__('Timestamp outdated.'));
        }

        return parent::handle($request, $next);
    }

    /**
     * @param Request $request
     * @return bool
     */
    protected function verifyHmac(Request $request): bool
    {
        $hmac = (string)$request->query('hmac');
        if (!$hmac) {
            return false;
        }

        $realHmac = $this->createHmac($request);

        if ($hmac != $realHmac) {
            return false;
        }

        return true;
    }

    /**
     * @param Request $request
     * @return string
     */
    protected function createHmac(Request $request): string
    {
        $data = $request->query();

        $sharedSecret = config('shopify-app.api_secret');

        //Get the hmac and remove it from array
        if (isset($data['hmac'])) {
            unset($data['hmac']);
        }
        //signature validation is deprecated
        if (isset($data['signature'])) {
            unset($data['signature']);
        }

        if (isset($data['change_pwd_flag'])) {
            unset($data['change_pwd_flag']);
        }

        if (isset($data['real_domain'])) {
            unset($data['real_domain']);
        }

        //Create data string for the remaining url parameters
        $dataString = http_build_query($data);

        $realHmac = hash_hmac('sha256', $dataString, $sharedSecret);

        return $realHmac;
    }
}