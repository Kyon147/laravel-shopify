<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <base target="_top">

    <title>Redirecting...</title>

    <script
        src="https://unpkg.com/@shopify/app-bridge{{ \Osiset\ShopifyApp\Util::getShopifyConfig('appbridge_version')? '@' . config('shopify-app.appbridge_version'): '' }}">
    </script>
    <script
        src="https://unpkg.com/@shopify/app-bridge-utils{{ \Osiset\ShopifyApp\Util::getShopifyConfig('appbridge_version')? '@' . config('shopify-app.appbridge_version'): '' }}">
    </script>
    <script @if (\Osiset\ShopifyApp\Util::getShopifyConfig('turbo_enabled')) data-turbolinks-eval="false" @endif>
        const redirectUrl = "{!! $authUrl !!}";
        const normalizedLink = document.createElement('a');
        normalizedLink.href = redirectUrl;

        if (window.top == window.self) {
            window.top.location.href = redirectUrl;
        } else {
            const AppBridge = window['app-bridge'];
            const utils = window['app-bridge-utils'];
            const createApp = AppBridge.default;
            const actions = AppBridge.actions;
            const Redirect = actions.Redirect;

            const app = createApp({
                apiKey: "{{ \Osiset\ShopifyApp\Util::getShopifyConfig('api_key', $shopDomain ?? Auth::user()->name) }}",
                shopOrigin: "{{ $shopDomain ?? Auth::user()->name }}",
                host: "{{ \Request::get('host') }}",
                forceRedirect: true,
            });

            Redirect.create(app)
                .dispatch(Redirect.Action.REMOTE, normalizedLink.href);
        }

    </script>
</head>

<body>
</body>

</html>
