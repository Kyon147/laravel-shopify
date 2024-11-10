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
            shopify.idToken().then((token) => {
                const host = new URLSearchParams(location.search).get("host");
                const url = new URL(`{!! $target !!}`, window.location.origin);

                url.searchParams.set('token', token);
                url.searchParams.set('host', host);

                open(url.toString(), "_self");
            });
        </script>
@endsection
