@php $isRights = $kind === 'rights'; @endphp
@extends('layouts.app')
@section('title', $isRights ? __('ui.contact.rights_title') : __('ui.contact.title'))

@section('content')
    <article class="prose prose--page">
        <h1>{{ $isRights ? __('ui.contact.rights_title') : __('ui.contact.title') }}</h1>

        @if ($isRights)
            <p class="lead">{{ __('ui.contact.rights_intro') }}</p>
            <p>{{ __('ui.contact.rights_limits') }}</p>
        @else
            <p class="lead">{{ __('ui.contact.contact_intro') }}</p>
        @endif

        @if (config('library.contact_email'))
            <p>{!! __('ui.contact.email_direct', ['email' => '<a href="mailto:'.e($isRights ? config('library.rights_email') : config('library.contact_email')).'"><bdi dir="ltr">'.e($isRights ? config('library.rights_email') : config('library.contact_email')).'</bdi></a>']) !!}</p>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger" role="alert">
                <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="post" action="{{ route('reports.store') }}">
            @csrf
            @if ($edition)
                <input type="hidden" name="edition" value="{{ $edition->slug }}">
                <p><strong>{{ __('ui.contact.about_edition', ['title' => $edition->title]) }}</strong></p>
            @endif

            <div class="mb-3">
                <label class="form-label" for="kind">{{ __('ui.contact.kind') }}</label>
                <select class="form-select" id="kind" name="kind">
                    @foreach (['contact', 'rights', 'accessibility'] as $option)
                        <option value="{{ $option }}" @selected(old('kind', $kind) === $option)>{{ __('ui.contact.kind_'.$option) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="name">{{ __('ui.contact.name') }}</label>
                <input class="form-control" id="name" name="name" type="text" value="{{ old('name') }}" required maxlength="120" autocomplete="name" dir="auto">
            </div>
            <div class="mb-3">
                <label class="form-label" for="email">{{ __('ui.contact.email') }}</label>
                <input class="form-control" id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="255" autocomplete="email" dir="ltr">
            </div>
            <div class="mb-3">
                <label class="form-label" for="message">{{ __('ui.contact.message') }}</label>
                <textarea class="form-control" id="message" name="message" rows="7" required minlength="10" maxlength="5000" dir="auto">{{ old('message') }}</textarea>
            </div>
            <div class="hp" aria-hidden="true">
                <label class="form-label" for="website">{{ __('ui.contact.honeypot') }}</label>
                <input class="form-control" id="website" name="website" type="text" tabindex="-1" autocomplete="off">
            </div>
            <button type="submit" class="btn btn-primary">{{ __('ui.contact.send') }}</button>
        </form>
    </article>
@endsection
