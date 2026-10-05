@extends('layouts.app', ['noindex' => true])
@section('title', __('ui.offline.title'))
@section('body-class', 'page-offline')

@section('content')
    <h1>{{ __('ui.offline.title') }}</h1>
    <p class="lead">{{ __('ui.offline.intro') }}</p>
    <p class="text-body-secondary small">{{ __('ui.offline.eviction') }}</p>

    <p class="alert alert-warning" hidden data-offline-unsupported>{{ __('ui.offline.unsupported') }}</p>
    <p class="text-body-secondary small" role="status" data-offline-storage></p>

    <ul class="list-group mb-4" data-offline-shelf></ul>
    <div class="card card-body text-center text-body-secondary py-5" hidden data-offline-empty>
        <p>{{ __('ui.offline.empty') }}</p>
        <a class="btn btn-primary" href="{{ route('catalog') }}">{{ __('ui.home.browse_catalog') }}</a>
    </div>

    <p><a href="{{ route('help', ['topic' => 'offline']) }}">{{ __('ui.help.offline') }}</a></p>

    @php
        $offlineStrings = trans('ui.offline') + ['manifestUrl' => url('/api/offline-manifest'), 'readUrl' => url('/'.app()->getLocale().'/read')];
    @endphp
    <script type="application/json" id="offline-strings">{!! json_encode($offlineStrings, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@endsection

@push('scripts')
    @vite('resources/js/offline-page.ts')
@endpush
