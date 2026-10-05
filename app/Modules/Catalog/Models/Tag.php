<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Localization\Concerns\HasMetadataTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    use HasMetadataTranslations;

    protected $guarded = ['id'];

    public function works(): BelongsToMany
    {
        return $this->belongsToMany(Work::class);
    }
}
