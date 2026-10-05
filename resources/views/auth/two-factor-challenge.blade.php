@extends('layouts.app', ['noindex' => true])
@section('title', __('ui.auth.two_factor_title'))

@section('content')
    <div class="card card-body shadow-sm mx-auto narrow">
        <h1>{{ __('ui.auth.two_factor_title') }}</h1>
        <p>{{ __('ui.auth.two_factor_intro') }}</p>
        @include('auth._errors')
        <form method="post" action="{{ route('two-factor.login') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label" for="code">{{ __('ui.auth.two_factor_code') }}</label>
                <input class="form-control" id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" autofocus dir="ltr">
            </div>
            <div class="mb-3">
                <label class="form-label" for="recovery_code">{{ __('ui.auth.two_factor_recovery') }}</label>
                <input class="form-control" id="recovery_code" name="recovery_code" type="text" autocomplete="off" dir="ltr">
            </div>
            <button type="submit" class="btn btn-primary">{{ __('ui.auth.sign_in') }}</button>
        </form>
    </div>
@endsection
