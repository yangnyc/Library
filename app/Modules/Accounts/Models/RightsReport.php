<?php

namespace App\Modules\Accounts\Models;

use App\Modules\Catalog\Models\Edition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A message from the public: a rights concern, an accessibility problem or general contact. */
class RightsReport extends Model
{
    protected $guarded = ['id'];

    public function edition(): BelongsTo
    {
        return $this->belongsTo(Edition::class);
    }
}
