@extends('layouts.app', ['noindex' => true])
@section('title', __('ui.auth.reset_title'))

@section('content')
    <div class="card card-body shadow-sm mx-auto narrow">
        <h1>{{ __('ui.auth.reset_title') }}</h1>
        @include('auth._errors')
        <form method="post" action="{{ route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $request->route('token') }}">
            <div class="mb-3">
                <label class="form-label" for="email">{{ __('ui.auth.email') }}</label>
                <input class="form-control" id="email" name="email" type="email" value="{{ old('email', $request->email) }}" required autocomplete="username" dir="ltr">
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">{{ __('ui.account.new_password') }}</label>
                <input class="form-control" id="password" name="password" type="password" required autocomplete="new-password" aria-describedby="password-rules" dir="ltr">
                <p id="password-rules" class="text-body-secondary small">{{ __('ui.auth.password_rules') }}</p>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password_confirmation">{{ __('ui.auth.password_confirm') }}</label>
                <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" dir="ltr">
            </div>
            <button type="submit" class="btn btn-primary">{{ __('ui.auth.reset_save') }}</button>
        </form>
    </div>
@endsection
