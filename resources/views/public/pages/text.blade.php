@php
    $content = trans('pages.'.$page);
    $replace = ['name' => config('library.name'), 'kindle_url' => config('library.kindle.send_to_kindle_url')];
    $fill = fn (string $text) => __($text, $replace);
@endphp
@extends('layouts.app')
@section('title', $fill($content['title']))

@section('content')
    <article class="prose prose--page">
        <h1>{{ $fill($content['title']) }}</h1>
        @if (! empty($content['lead']))
            <p class="lead">{{ $fill($content['lead']) }}</p>
        @endif
        @foreach ($content['sections'] as [$heading, $paragraphs])
            <h2>{{ $fill($heading) }}</h2>
            @foreach ($paragraphs as $paragraph)
                <p>{{ $fill($paragraph) }}</p>
            @endforeach
        @endforeach
        @yield('page-extra')
    </article>
@endsection
