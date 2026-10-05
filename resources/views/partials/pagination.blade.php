@if ($paginator->hasPages())
    <nav aria-label="{{ __('ui.catalog.pagination') }}">
        <ul class="pagination">
            <li class="page-item @if($paginator->onFirstPage()) disabled @endif">
                @if ($paginator->onFirstPage())
                    <span class="page-link" aria-disabled="true">{{ __('ui.catalog.previous') }}</span>
                @else
                    <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('ui.catalog.previous') }}</a>
                @endif
            </li>
            @foreach ($elements as $element)
                @if (is_string($element))
                    <li class="page-item disabled"><span class="page-link" aria-hidden="true">…</span></li>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li class="page-item active">
                                <span class="page-link" aria-current="page"><span class="visually-hidden">{{ __('ui.catalog.page', ['page' => '']) }}</span>{{ $page }}</span>
                            </li>
                        @else
                            <li class="page-item">
                                <a class="page-link" href="{{ $url }}" aria-label="{{ __('ui.catalog.page', ['page' => $page]) }}">{{ $page }}</a>
                            </li>
                        @endif
                    @endforeach
                @endif
            @endforeach
            <li class="page-item @if(! $paginator->hasMorePages()) disabled @endif">
                @if ($paginator->hasMorePages())
                    <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('ui.catalog.next') }}</a>
                @else
                    <span class="page-link" aria-disabled="true">{{ __('ui.catalog.next') }}</span>
                @endif
            </li>
        </ul>
    </nav>
@endif
