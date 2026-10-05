@extends('layouts.app', ['noindex' => true])
@section('title', __('ui.account.title'))
@section('body-class', 'page-account')

@section('content')
    <h1>{{ __('ui.account.title') }}</h1>
    <nav class="nav nav-pills gap-2 mb-4" aria-label="{{ __('ui.account.title') }}">
        <a class="nav-link border" href="#continue-heading">{{ __('ui.account.continue') }}</a>
        <a class="nav-link border" href="#favorites-heading">{{ __('ui.account.favorites') }}</a>
        <a class="nav-link border" href="#lists-heading">{{ __('ui.account.lists') }}</a>
        <a class="nav-link border" href="{{ route('account.security') }}">{{ __('ui.account.security') }}</a>
    </nav>

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    {{-- Shown by script only when this browser holds guest reading data. --}}
    <section class="alert alert-info" hidden data-guest-merge aria-labelledby="merge-heading">
        <h2 id="merge-heading">{{ __('ui.account.merge_title') }}</h2>
        <p>{{ __('ui.account.merge_text') }}</p>
        <p>
            <button type="button" class="btn btn-primary" data-guest-merge-accept>{{ __('ui.account.merge_do') }}</button>
            <button type="button" class="btn btn-outline-secondary" data-guest-merge-skip>{{ __('ui.account.merge_skip') }}</button>
        </p>
        <p hidden role="status" data-guest-merge-done>{{ __('ui.account.merge_done') }}</p>
    </section>

    <section class="card card-body mb-3" aria-labelledby="continue-heading">
        <h2 id="continue-heading" class="card-title">{{ __('ui.account.continue') }}</h2>
        @if ($progress->isEmpty())
            <p class="text-body-secondary small">{{ __('ui.account.no_progress') }}</p>
        @else
            <ul class="list-group list-group-flush mb-3">
                @foreach ($progress as $item)
                    @continue(! $item->edition || ! $item->edition->isPublished())
                    <li class="list-group-item d-flex flex-wrap align-items-center gap-2 px-0">
                        <a href="{{ route('read', ['edition' => $item->edition, 'format' => $item->file?->format]) }}">
                            <x-bdi :lang="$item->edition->language_tag" :dir="$item->edition->direction">{{ $item->edition->title }}</x-bdi>
                        </a>
                        @if ($item->label)<span class="text-body-secondary small">— <bdi>{{ $item->label }}</bdi></span>@endif
                        @if ($item->file && ! $item->file->is_current)<span class="badge bg-transparent">{{ __('ui.account.earlier_version') }}</span>@endif
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($staleCount > 0)
            <p class="text-body-secondary small">{{ trans_choice('ui.account.stale', $staleCount, ['count' => $staleCount]) }}</p>
        @endif
    </section>

    <section class="card card-body mb-3" aria-labelledby="favorites-heading">
        <h2 id="favorites-heading" class="card-title">{{ __('ui.account.favorites') }}</h2>
        @if ($favorites->isEmpty())
            <p class="text-body-secondary small">{{ __('ui.account.no_favorites') }}</p>
        @else
            <ul class="list-group list-group-flush mb-3">
                @foreach ($favorites as $work)
                    <li class="list-group-item d-flex flex-wrap align-items-center gap-2 px-0">
                        <a href="{{ route('works.show', $work) }}"><x-bdi :lang="$work->localizedLanguage('title') ?: $work->original_language_tag">{{ $work->localized('title') ?: $work->original_title }}</x-bdi></a>
                        <form method="post" action="{{ route('account.favorites.toggle', $work) }}" class="ms-auto">
                            @csrf
                            <button type="submit" class="btn btn-link p-0 align-baseline">{{ __('ui.account.remove') }}</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="card card-body mb-3" aria-labelledby="lists-heading">
        <h2 id="lists-heading" class="card-title">{{ __('ui.account.lists') }}</h2>
        @if ($lists->isEmpty())
            <p class="text-body-secondary small">{{ __('ui.account.no_lists') }}</p>
        @endif
        @foreach ($lists as $list)
            <section class="card card-body mb-3" aria-labelledby="list-{{ $list->id }}">
                <h3 id="list-{{ $list->id }}"><bdi>{{ $list->name }}</bdi></h3>
                @if ($list->editions->isEmpty())
                    <p class="text-body-secondary small">{{ __('ui.account.empty_list') }}</p>
                @else
                    <ul class="list-group list-group-flush mb-3">
                        @foreach ($list->editions as $edition)
                            <li class="list-group-item d-flex flex-wrap align-items-center gap-2 px-0">
                                <a href="{{ route('editions.show', $edition) }}"><x-bdi :lang="$edition->language_tag" :dir="$edition->direction">{{ $edition->title }}</x-bdi></a>
                                <form method="post" action="{{ route('account.lists.items.remove', ['list' => $list->id, 'edition' => $edition]) }}" class="ms-auto">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-link p-0 align-baseline">{{ __('ui.account.remove') }}</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <form method="post" action="{{ route('account.lists.destroy', ['list' => $list->id]) }}">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-link p-0 align-baseline">{{ __('ui.account.delete_list') }}</button>
                </form>
            </section>
        @endforeach

        <form method="post" action="{{ route('account.lists.store') }}" class="d-inline-flex flex-wrap align-items-center gap-2">
            @csrf
            <label class="form-label mb-0" for="list-name">{{ __('ui.account.new_list') }}</label>
            <input class="form-control w-auto" id="list-name" name="name" type="text" required maxlength="120" dir="auto">
            <button type="submit" class="btn btn-primary btn-sm">{{ __('ui.account.create_list') }}</button>
        </form>
    </section>

    <section class="card card-body mb-3" aria-labelledby="security-heading">
        <h2 id="security-heading" class="card-title">{{ __('ui.account.security') }}</h2>
        <p><a href="{{ route('account.security') }}">{{ __('ui.account.security') }}</a></p>
        <p class="text-body-secondary small">{{ __('ui.account.sign_out_note') }}</p>
    </section>

    <section class="card card-body mb-3" aria-labelledby="data-heading">
        <h2 id="data-heading" class="card-title">{{ __('ui.account.data') }}</h2>
        <p><a class="btn btn-outline-secondary" href="{{ route('account.export') }}">{{ __('ui.account.export') }}</a></p>
        <p class="text-body-secondary small">{{ __('ui.account.export_hint') }}</p>

        <h3>{{ __('ui.account.delete') }}</h3>
        <p class="text-body-secondary small">{{ __('ui.account.delete_hint') }}</p>
        <form method="post" action="{{ route('account.destroy') }}" data-logout-form data-user-id="{{ $user->id }}">
            @csrf @method('DELETE')
            <div class="mb-3">
                <label class="form-label" for="delete-password">{{ __('ui.account.delete_confirm') }}</label>
                <input class="form-control" id="delete-password" name="password" type="password" required autocomplete="current-password" dir="ltr">
            </div>
            <button type="submit" class="btn btn-danger">{{ __('ui.account.delete') }}</button>
        </form>
    </section>
@endsection

@push('scripts')
    @vite('resources/js/account.ts')
@endpush
