@extends('shopify-app::layouts.default')

@section('styles')
    @include('shopify-app::partials.polaris_skeleton_css')
@endsection

@section('content')
    <div>
        <div class="Polaris-SkeletonPage__Page" role="status" aria-label="Page loading">
            <div class="Polaris-SkeletonPage__Header">
                <div class="Polaris-SkeletonPage__TitleAndPrimaryAction">
                    <div class="Polaris-SkeletonPage__TitleWrapper">
                        <div class="Polaris-SkeletonPage__SkeletonTitle"></div>
                    </div>
                </div>
            </div>
            <div class="Polaris-SkeletonPage__Content">
                <div class="Polaris-Layout">
                    <div class="Polaris-Layout__Section">
                        <div class="Polaris-Card">
                            <div class="Polaris-Card__Section">
                                <div class="Polaris-SkeletonBodyText__SkeletonBodyTextContainer">
                                <div class="Polaris-SkeletonBodyText"></div>
                                <div class="Polaris-SkeletonBodyText"></div>
                                <div class="Polaris-SkeletonBodyText"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
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
                    open("{{ route('home') }}", "_self");
                }

                shopify.idToken().then((token) => {

                    let url = new URL(`{!! $target !!}`, window.location.origin);
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
