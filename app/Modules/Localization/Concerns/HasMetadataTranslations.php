<?php

namespace App\Modules\Localization\Concerns;

use App\Modules\Localization\Locales;
use App\Modules\Localization\Models\MetadataTranslation;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Localized catalog metadata (names, descriptions) for a model.
 *
 * The metadata language is independent of the interface locale and of any
 * edition language: localized() is asked for a language and falls back to the
 * model's own column when nothing has been entered for it.
 */
trait HasMetadataTranslations
{
    public function translations(): MorphMany
    {
        return $this->morphMany(MetadataTranslation::class, 'translatable');
    }

    public function localized(string $field, ?string $languageTag = null): ?string
    {
        $languageTag ??= Locales::metadataLanguage();

        // Uses the loaded relation when present to avoid N+1 queries in lists.
        $match = $this->translations->first(
            fn (MetadataTranslation $t) => $t->field === $field && $t->language_tag === $languageTag
        );

        return $match?->value ?: $this->getAttribute($field);
    }

    /** Language tag of the value localized() would return, for lang/dir attributes. */
    public function localizedLanguage(string $field, ?string $languageTag = null): ?string
    {
        $languageTag ??= Locales::metadataLanguage();

        $match = $this->translations->first(
            fn (MetadataTranslation $t) => $t->field === $field && $t->language_tag === $languageTag && $t->value !== ''
        );

        return $match ? $languageTag : null;
    }

    /** @param array<string, array<string, ?string>> $values [language_tag => [field => value]] */
    public function syncTranslations(array $values): void
    {
        foreach ($values as $languageTag => $fields) {
            foreach ($fields as $field => $value) {
                $value = is_string($value) ? trim($value) : '';
                $key = ['language_tag' => $languageTag, 'field' => $field];
                if ($value === '') {
                    $this->translations()->where($key)->delete();
                } else {
                    $this->translations()->updateOrCreate($key, ['value' => $value]);
                }
            }
        }
        $this->unsetRelation('translations');
    }
}
