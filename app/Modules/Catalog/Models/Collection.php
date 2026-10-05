<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Localization\Concerns\HasMetadataTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A curated, ordered set of works. */
class Collection extends Model
{
    use HasMetadataTranslations;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_featured' => 'boolean', 'is_published' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function works(): BelongsToMany
    {
        return $this->belongsToMany(Work::class)->withPivot('position')->orderByPivot('position');
    }
}
