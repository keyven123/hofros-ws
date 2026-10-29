<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingProspectNote extends Model
{
    use HasUuids;

    public $incrementing = false;

    public $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $fillable = [
        'prospect_uuid',
        'body',
    ];

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(MarketingProspect::class, 'prospect_uuid', 'uuid');
    }
}
