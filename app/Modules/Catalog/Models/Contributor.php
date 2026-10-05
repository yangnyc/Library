<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Localization\Concerns\HasMetadataTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Contributor extends Model
{
    use HasMetadataTranslations;

    public const ROLES = ['author', 'translator', 'editor', 'illustrator', 'introduction'];

    protected $guarded = ['id'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function works(): MorphToMany
    {
        return $this->morphedByMany(Work::class, 'contributable', 'contributions')->withPivot('role', 'position');
    }

    public function editions(): MorphToMany
    {
        return $this->morphedByMany(Edition::class, 'contributable', 'contributions')->withPivot('role', 'position');
    }
}
