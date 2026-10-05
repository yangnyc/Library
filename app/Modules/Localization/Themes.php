<?php

namespace App\Modules\Localization;

use Illuminate\Http\Request;

/**
 * Interface colour themes. The choice lives in a cookie and is rendered by the
 * server as <body data-ui-theme="…">, so it applies without any script. The
 * colours themselves are in resources/css/app.css.
 */
class Themes
{
    public const COOKIE = 'ui_theme';

    /** Follows the device's light or dark setting. */
    public const DEFAULT = 'auto';

    /** @return array<string, array{color: string, scheme: string}> */
    public static function all(): array
    {
        return config('library.themes');
    }

    public static function current(?Request $request = null): string
    {
        $theme = ($request ?? request())->cookie(self::COOKIE);

        return is_string($theme) && isset(self::all()[$theme]) ? $theme : self::DEFAULT;
    }

    /** Browser toolbar colour for a theme; the default theme keeps the branding setting. */
    public static function color(string $theme): string
    {
        return $theme === self::DEFAULT ? config('library.theme_color') : self::all()[$theme]['color'];
    }
}
