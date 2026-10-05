@extends('layouts.app', ['noindex' => true])
@section('title', __('ui.auth.reset_title'))

@section('content')
    <div class="card card-body shadow-sm mx-auto narrow">
        <h1>{{ __('ui.auth.reset_title') }}</h1>
        <p>{{ __('ui.auth.reset_intro') }}</p>
        @include('auth._errors')
        <form method="post" action="{{ route('password.email') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label" for="email">{{ __('ui.auth.email') }}</label>
                <input class="form-control" id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username" dir="ltr">
            </div>
            <button type="submit" class="btn btn-primary">{{ __('ui.auth.reset_send') }}</button>
        </form>
    </div>
@endsection
