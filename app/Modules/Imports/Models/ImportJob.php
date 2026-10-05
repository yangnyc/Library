<?php

namespace App\Modules\Imports\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Edition;
use App\Modules\Catalog\Models\EditionFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportJob extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'extracted_metadata' => 'array',
            'report' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(Edition::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(EditionFile::class, 'edition_file_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
