<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <base target="_top">

        <title>Redirecting...</title>
        <script src="https://unpkg.com/@shopify/app-bridge{{ \Osiset\ShopifyApp\Util::getShopifyConfig('appbridge_version') ? '@'.config('shopify-app.appbridge_version') : '' }}"></script>
        <script type="text/javascript">
            const redirectUrl = "{!! $url !!}";

            const AppBridge = window['app-bridge'];
            const createApp = AppBridge.default;
            const Redirect = AppBridge.actions.Redirect;
            const app = createApp({
                apiKey: "{{ $apiKey }}",
                host: "{{ $host }}",
            });

            console.log( 'app', app );

            const redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.REMOTE, redirectUrl);
        </script>
    </head>
    <body>
    </body>
</html>
