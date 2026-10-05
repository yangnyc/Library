<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Localization\Concerns\HasMetadataTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/** The underlying intellectual work; editions hang off it. */
class Work extends Model
{
    use HasMetadataTranslations;

    protected $guarded = ['id'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function editions(): HasMany
    {
        return $this->hasMany(Edition::class);
    }

    public function publishedEditions(): HasMany
    {
        return $this->editions()->published();
    }

    public function authors(): MorphToMany
    {
        return $this->morphToMany(Contributor::class, 'contributable', 'contributions')
            ->withPivot('role', 'position')->wherePivot('role', 'author')->orderByPivot('position');
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class)->withPivot('position');
    }

    public function relatedWorks(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'work_relations', 'work_id', 'related_work_id')->withPivot('type');
    }
}
