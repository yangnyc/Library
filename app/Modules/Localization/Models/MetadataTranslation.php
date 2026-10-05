<?php

namespace App\Modules\Localization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MetadataTranslation extends Model
{
    protected $guarded = ['id'];

    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }
}
