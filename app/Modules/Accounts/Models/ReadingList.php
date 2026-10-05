<?php

namespace App\Modules\Accounts\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Edition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ReadingList extends Model
{
    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function editions(): BelongsToMany
    {
        return $this->belongsToMany(Edition::class, 'reading_list_items')
            ->withPivot('position')->withTimestamps()->orderByPivot('position');
    }
}
