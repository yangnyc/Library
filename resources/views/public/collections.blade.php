@extends('layouts.app')
@section('title', __('ui.collections.title'))

@section('content')
    <h1>{{ __('ui.collections.title') }}</h1>
    <p class="lead">{{ __('ui.collections.intro') }}</p>

    @if ($collections->isEmpty())
        <p class="card card-body text-center text-body-secondary py-5">{{ __('ui.home.empty') }}</p>
    @else
        <ul class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3 list-unstyled">
            @foreach ($collections as $collection)
                <li class="col">
                  <div class="card card-body h-100">
                    <h2 class="card-title"><a href="{{ route('collections.show', $collection) }}"><x-bdi :lang="$collection->localizedLanguage('name')">{{ $collection->localized('name') }}</x-bdi></a></h2>
                    @if ($collection->localized('description'))
                        <p dir="auto">{{ \Illuminate\Support\Str::limit($collection->localized('description'), 240) }}</p>
                    @endif
                    <p class="text-body-secondary small mt-auto mb-0">{{ trans_choice('ui.collections.works_count', $collection->works_count, ['count' => $collection->works_count]) }}</p>
                  </div>
                </li>
            @endforeach
        </ul>
        {{ $collections->links() }}
    @endif
@endsection
