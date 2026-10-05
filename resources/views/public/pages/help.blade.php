@php
    use App\Modules\Catalog\Http\PageController;
    $content = trans('pages.help_'.$topic);
    $kindleUrl = config('library.kindle.send_to_kindle_url');
    $fill = fn (string $text) => __($text, ['name' => config('library.name'), 'kindle_url' => $kindleUrl]);
@endphp
@extends('layouts.app')
@section('title', $content['title'])

@section('content')
    <div class="row g-4 g-lg-5">
        <nav class="col-md-4 col-lg-3" aria-label="{{ __('ui.help.topics') }}">
            <h2 class="h5">{{ __('ui.help.title') }}</h2>
            <ul class="nav nav-pills flex-column">
                @foreach (PageController::HELP_TOPICS as $item)
                    <li class="nav-item"><a class="nav-link @if($item === $topic) active @endif" href="{{ route('help', ['topic' => $item]) }}" @if($item === $topic) aria-current="page" @endif>{{ __('ui.help.'.$item) }}</a></li>
                @endforeach
            </ul>
        </nav>

        <article class="prose prose--page col-md-8 col-lg-9">
            <h1>{{ $content['title'] }}</h1>
            <p class="lead">{{ $fill($content['lead']) }}</p>

            @foreach ($content['sections'] as [$heading, $paragraphs])
                <h2>{{ $heading }}</h2>
                @foreach ($paragraphs as $paragraph)
                    <p>{{ $fill($paragraph) }}</p>
                @endforeach
            @endforeach

            @if ($topic === 'kindle')
                {{-- The only place the site links to Amazon: their official page, opened by the reader. --}}
                <p><a class="btn btn-outline-secondary" href="{{ $kindleUrl }}" rel="noopener noreferrer external"><span dir="ltr" lang="en">Send to Kindle</span> — <bdi dir="ltr">amazon.com/sendtokindle</bdi></a></p>
            @endif

            @if ($topic === 'offline')
                <p><button type="button" class="btn btn-primary" hidden data-install-button>{{ __('ui.help.offline') }}</button></p>
                <p><a href="{{ route('offline') }}">{{ __('ui.nav.offline') }}</a></p>
            @endif
        </article>
    </div>
@endsection
