@php /** @var \App\Modules\Catalog\Models\Edition $edition */ @endphp
@if ($edition->cover_path)
    <img class="cover card-img-top" src="{{ \Illuminate\Support\Facades\Storage::disk('covers')->url($edition->cover_path) }}"
         alt="{{ ($decorative ?? true) ? '' : __('ui.edition.cover_of', ['title' => $edition->title]) }}"
         width="320" height="480" loading="lazy" decoding="async">
@else
    {{-- Generated placeholder: the title set in the edition's own script and direction. --}}
    <span class="cover cover--placeholder card-img-top" lang="{{ $edition->language_tag }}" dir="{{ $edition->direction }}" aria-hidden="true">
        <span>{{ \Illuminate\Support\Str::limit($edition->title, 60) }}</span>
    </span>
@endif
