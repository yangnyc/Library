@props(['lang' => null, 'dir' => null])
@php
    // Text in a known language gets that language and its direction; anything
    // else is isolated and left to the browser's first-strong-character rule.
    $direction = $dir ?? ($lang ? \App\Modules\Localization\Models\Language::directionFor($lang) : 'auto');
    $direction = $direction === 'auto' ? 'auto' : $direction;
@endphp
<bdi @if($lang) lang="{{ $lang }}" @endif dir="{{ $direction }}" {{ $attributes }}>{{ $slot }}</bdi>