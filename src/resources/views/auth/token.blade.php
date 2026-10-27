@extends('shopify-app::layouts.default')

@section('styles')
    @include('shopify-app::partials.token_loading_styles')
@endsection

@section('content')
    @include('shopify-app::partials.token_loading')
@endsection

@section('scripts')
    @parent
        <script>
                // If no host is found, we need to throw an error
                const host = new URLSearchParams(location.search).get("host");
                if (!host) {
                    throw new Error('No host found in the URL');
                }

                // If shopify is not defined, then we are not in a Shopify context redirect to the homepage as it
                if (typeof shopify === 'undefined') {
                    open("{{ route(\Osiset\ShopifyApp\Util::getShopifyConfig('route_names.home')) }}", "_self");
                }

                shopify.idToken().then((token) => {

                    let url = new URL(@json($target), window.location.origin);

                    if (url.origin !== window.location.origin) {
                        url = new URL(@json($fallbackTarget), window.location.origin);
                    }

                    // Enforce HTTPS if the current page is using HTTPS
                    if (window.location.protocol === 'https:') {
                        url.protocol = 'https:';
                    }

                    url.searchParams.set('token', token);
                    url.searchParams.set('host', host);

                    open(url.toString(), "_self");
                    history.pushState(null, '', url.toString());
                });
        </script>
@endsection
