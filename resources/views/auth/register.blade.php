@extends('layouts.app', ['noindex' => true])
@section('title', __('ui.auth.register'))

@section('content')
    <div class="card card-body shadow-sm mx-auto narrow">
        <h1>{{ __('ui.auth.register') }}</h1>
        <p class="text-body-secondary small">{{ __('ui.auth.optional') }}</p>
        @include('auth._errors')

        <form method="post" action="{{ route('register') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label" for="name">{{ __('ui.auth.name') }}</label>
                <input class="form-control" id="name" name="name" type="text" value="{{ old('name') }}" required maxlength="255" autocomplete="name" dir="auto">
            </div>
            <div class="mb-3">
                <label class="form-label" for="email">{{ __('ui.auth.email') }}</label>
                <input class="form-control" id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username" dir="ltr">
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">{{ __('ui.auth.password') }}</label>
                <input class="form-control" id="password" name="password" type="password" required autocomplete="new-password" aria-describedby="password-rules" dir="ltr">
                <p id="password-rules" class="text-body-secondary small">{{ __('ui.auth.password_rules') }}</p>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password_confirmation">{{ __('ui.auth.password_confirm') }}</label>
                <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" dir="ltr">
            </div>
            <button type="submit" class="btn btn-primary">{{ __('ui.nav.register') }}</button>
        </form>
        <p>{{ __('ui.auth.have_account') }} <a href="{{ route('login') }}">{{ __('ui.auth.sign_in') }}</a></p>
    </div>
@endsection
