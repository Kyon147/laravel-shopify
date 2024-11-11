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

    @if(config('shopify-app.appbridge_enabled'))
        <script>
            const host = new URLSearchParams(location.search).get("host");

            shopify.idToken().then((token) => {
                // Construct the initial target path and convert it into a URL object
                let targetPath = `{!! $target !!}{!! Str::contains($target, '?') ? '&' : '?' !!}id_token=${token}{{ Str::contains($target, 'host') ? '' : '&host=${host}'}}`;
                const targetUrl = new URL(targetPath, window.location.origin); // Uses current origin to build URL

                // Parse and update search parameters from the target URL
                const urlParams = targetUrl.searchParams;
                urlParams.set('id_token', token); // Ensure 'id_token' is set or updated
                if (host) {
                    urlParams.set('host', host); // Ensure 'host' is set if it was not in the target
                }

                // Enforce HTTPS if the current page is using HTTPS
                if (window.location.protocol === 'https:') {
                    targetUrl.protocol = 'https:';
                }

                // Only push to history if the final URL is different from the current URL
                if (window.location.href !== targetUrl.href) {
                    window.location = targetUrl; // Redirect to the target URL
                    history.pushState(null, '', targetUrl.href); // Update the URL in the history without a page reload
                }
            });
        </script>
    @endif
@endsection
