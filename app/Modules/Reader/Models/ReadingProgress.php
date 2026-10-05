<?php

namespace App\Modules\Reader\Models;

use App\Modules\Catalog\Models\Edition;
use App\Modules\Catalog\Models\EditionFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReadingProgress extends Model
{
    protected $table = 'reading_progress';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['client_updated_at' => 'datetime', 'fraction' => 'float'];
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(Edition::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(EditionFile::class, 'edition_file_id');
    }
}
