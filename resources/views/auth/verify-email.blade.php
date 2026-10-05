@extends('layouts.app', ['noindex' => true])
@section('title', __('ui.auth.verify_title'))

@section('content')
    <div class="card card-body shadow-sm mx-auto narrow">
        <h1>{{ __('ui.auth.verify_title') }}</h1>
        <p>{{ __('ui.auth.verify_intro') }}</p>
        @if (session('status') === 'verification-link-sent')
            <div class="alert alert-success" role="status">{{ __('ui.auth.verify_sent') }}</div>
        @endif
        <form method="post" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="btn btn-primary">{{ __('ui.auth.verify_resend') }}</button>
        </form>
    </div>
@endsection
