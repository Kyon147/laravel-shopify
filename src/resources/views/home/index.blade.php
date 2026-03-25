@extends('shopify-app::layouts.default')

@section('styles')
    @include('shopify-app::partials.laravel_skeleton_css')
@endsection

@section('content')
    <ui-title-bar title="Welcome"></ui-title-bar>

    <div class="flex-center position-ref full-height">
        <div class="content">
            <div class="title m-b-md">
                Laravel &amp; Shopify
            </div>

            <p>Welcome to your Shopify App powered by Laravel.</p>
            <p>&nbsp;</p>
            <p>{{ $shop->name }}</p>
            <p>&nbsp;</p>

            <div class="links">
                <a href="https://github.com/Kyon147/laravel-shopify" target="_blank">Package</a>
                <a href="https://laravel.com" target="_blank">Laravel</a>
                <a href="https://github.com/Kyon147/laravel-shopify" target="_blank">GitHub</a>
            </div>
        </div>
    </div>
@endsection
