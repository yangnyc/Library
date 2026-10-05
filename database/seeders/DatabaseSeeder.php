<?php

namespace Database\Seeders;

use App\Modules\Localization\Models\Language;
use Illuminate\Database\Seeder;

/**
 * Safe to run on production: creates only the reference data the application
 * needs. Demonstration books are a separate, optional seeder:
 *
 *     php artisan db:seed --class=DemoLibrarySeeder
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $languages = [
            ['tag' => 'en', 'native_name' => 'English', 'english_name' => 'English', 'direction' => 'ltr'],
            ['tag' => 'ru', 'native_name' => 'Русский', 'english_name' => 'Russian', 'direction' => 'ltr'],
            ['tag' => 'he', 'native_name' => 'עברית', 'english_name' => 'Hebrew', 'direction' => 'rtl'],
        ];

        foreach ($languages as $position => $language) {
            Language::query()->firstOrCreate(['tag' => $language['tag']], $language + ['position' => $position]);
        }
    }
}
