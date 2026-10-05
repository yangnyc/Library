<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Localization\Concerns\HasMetadataTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Series extends Model
{
    use HasMetadataTranslations;

    protected $table = 'series';

    protected $guarded = ['id'];

    public function works(): HasMany
    {
        return $this->hasMany(Work::class)->orderBy('series_position');
    }
}
