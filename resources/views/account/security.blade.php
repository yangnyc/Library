@extends('layouts.app', ['noindex' => true])
@section('title', __('ui.account.security'))

@section('content')
    <div class="card card-body shadow-sm mx-auto narrow">
        <h1>{{ __('ui.account.security') }}</h1>
        <p><a href="{{ route('account.show') }}">{{ __('ui.account.title') }}</a></p>

        @if ($errors->any() || $errors->updatePassword->any() || $errors->updateProfileInformation->any())
            <div class="alert alert-danger" role="alert">
                <ul>
                    @foreach (array_merge($errors->all(), $errors->updatePassword->all(), $errors->updateProfileInformation->all()) as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if (in_array(session('status'), ['password-updated', 'profile-information-updated'], true))
            <div class="alert alert-success" role="status">{{ __('ui.account.saved') }}</div>
        @endif

        <section aria-labelledby="profile-heading">
            <h2 id="profile-heading">{{ __('ui.account.profile') }}</h2>
            <form method="post" action="{{ route('user-profile-information.update') }}">
                @csrf @method('PUT')
                <div class="mb-3">
                    <label class="form-label" for="name">{{ __('ui.auth.name') }}</label>
                    <input class="form-control" id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required maxlength="255" autocomplete="name" dir="auto">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="email">{{ __('ui.auth.email') }}</label>
                    <input class="form-control" id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required autocomplete="username" dir="ltr">
                </div>
                <button type="submit" class="btn btn-primary">{{ __('ui.account.save') }}</button>
            </form>
        </section>

        <section aria-labelledby="password-heading">
            <h2 id="password-heading">{{ __('ui.account.change_password') }}</h2>
            <form method="post" action="{{ route('user-password.update') }}">
                @csrf @method('PUT')
                <div class="mb-3">
                    <label class="form-label" for="current_password">{{ __('ui.account.current_password') }}</label>
                    <input class="form-control" id="current_password" name="current_password" type="password" required autocomplete="current-password" dir="ltr">
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
                <button type="submit" class="btn btn-primary">{{ __('ui.account.save') }}</button>
            </form>
        </section>

        <section aria-labelledby="tfa-heading">
            <h2 id="tfa-heading">{{ __('ui.account.two_factor') }}</h2>

            @if ($user->hasTwoFactor())
                <p role="status">{{ __('ui.account.two_factor_on') }}</p>
                @if (session('status') === 'two-factor-authentication-confirmed')
                    <p>{{ __('ui.account.two_factor_codes') }}</p>
                    <ul class="codes" dir="ltr">
                        @foreach ($user->recoveryCodes() as $code)<li><code>{{ $code }}</code></li>@endforeach
                    </ul>
                @endif
                <form method="post" action="{{ route('two-factor.disable') }}">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-outline-secondary">{{ __('ui.account.two_factor_disable') }}</button>
                </form>
            @elseif ($user->two_factor_secret)
                {{-- Enabled but not yet confirmed with a code. --}}
                <p>{{ __('ui.account.two_factor_scan') }}</p>
                <div class="qr">{!! $user->twoFactorQrCodeSvg() !!}</div>
                <p>{{ __('ui.account.two_factor_key') }}: <code dir="ltr">{{ decrypt($user->two_factor_secret) }}</code></p>
                <form method="post" action="{{ route('two-factor.confirm') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="code">{{ __('ui.auth.two_factor_code') }}</label>
                        <input class="form-control" id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required dir="ltr">
                    </div>
                    <button type="submit" class="btn btn-primary">{{ __('ui.account.two_factor_confirm') }}</button>
                </form>
            @else
                <p>{{ __('ui.account.two_factor_off') }}</p>
                <form method="post" action="{{ route('two-factor.enable') }}">
                    @csrf
                    <button type="submit" class="btn btn-primary">{{ __('ui.account.two_factor_enable') }}</button>
                </form>
            @endif
        </section>
    </div>
@endsection
