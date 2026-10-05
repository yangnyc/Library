<?php

namespace App\Modules\Localization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Language extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('languages.all'));
        static::deleted(fn () => Cache::forget('languages.all'));
    }

    /** @return array<string, array{tag:string, native_name:string, english_name:string, direction:string}> */
    public static function map(): array
    {
        return Cache::remember('languages.all', 3600, fn () => static::query()
            ->where('is_active', true)->orderBy('position')->orderBy('english_name')
            ->get(['tag', 'native_name', 'english_name', 'direction'])
            ->keyBy('tag')->map->toArray()->all());
    }

    public static function nativeName(string $tag): string
    {
        $map = static::map();

        return $map[$tag]['native_name'] ?? $map[strtok($tag, '-')]['native_name'] ?? $tag;
    }

    public static function directionFor(string $tag): string
    {
        $map = static::map();

        return $map[$tag]['direction'] ?? $map[strtok($tag, '-')]['direction'] ?? 'ltr';
    }
}
