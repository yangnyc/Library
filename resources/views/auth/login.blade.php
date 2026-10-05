@extends('layouts.app', ['noindex' => true])
@section('title', __('ui.auth.sign_in'))

@section('content')
    <div class="card card-body shadow-sm mx-auto narrow">
        <h1>{{ __('ui.auth.sign_in') }}</h1>
        <p class="text-body-secondary small">{{ __('ui.auth.optional') }}</p>
        @include('auth._errors')

        <form method="post" action="{{ route('login') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label" for="email">{{ __('ui.auth.email') }}</label>
                <input class="form-control" id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" dir="ltr">
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">{{ __('ui.auth.password') }}</label>
                <input class="form-control" id="password" name="password" type="password" required autocomplete="current-password" dir="ltr">
            </div>
            <div class="mb-3 form-check">
                <input class="form-check-input" id="remember" name="remember" type="checkbox" value="1">
                <label class="form-check-label" for="remember">{{ __('ui.auth.remember') }}</label>
            </div>
            <button type="submit" class="btn btn-primary">{{ __('ui.auth.sign_in') }}</button>
        </form>

        @if (Route::has('password.request'))
            <p><a href="{{ route('password.request') }}">{{ __('ui.auth.forgot') }}</a></p>
        @else
            {{-- Mail is not configured: say so instead of offering a reset that cannot arrive. --}}
            <p class="text-body-secondary small">{{ __('ui.auth.no_reset') }}</p>
        @endif
        <p>{{ __('ui.auth.no_account') }} <a href="{{ route('register') }}">{{ __('ui.nav.register') }}</a></p>
    </div>
@endsection
