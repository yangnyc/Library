@extends('layouts.app', ['noindex' => true])
@section('title', __('ui.auth.confirm_title'))

@section('content')
    <div class="card card-body shadow-sm mx-auto narrow">
        <h1>{{ __('ui.auth.confirm_title') }}</h1>
        <p>{{ __('ui.auth.confirm_intro') }}</p>
        @include('auth._errors')
        <form method="post" action="{{ route('password.confirm.store') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label" for="password">{{ __('ui.auth.password') }}</label>
                <input class="form-control" id="password" name="password" type="password" required autofocus autocomplete="current-password" dir="ltr">
            </div>
            <button type="submit" class="btn btn-primary">{{ __('ui.auth.confirm') }}</button>
        </form>
    </div>
@endsection
