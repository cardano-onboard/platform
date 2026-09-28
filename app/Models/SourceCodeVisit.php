<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A day's visit count for one source code.
 *
 * A counter, not a log. Nothing here identifies a visitor, and there is no row per hit to
 * identify one from.
 */
class SourceCodeVisit extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'source_code_visits';

    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'visits' => 'integer',
    ];

    public function sourceCode(): BelongsTo
    {
        return $this->belongsTo(SourceCode::class, 'code', 'code');
    }
}
