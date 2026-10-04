<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shift extends Model
{
    protected $fillable = [
        'employee_id', 'check_in_at', 'check_out_at',
        'check_in_lat', 'check_in_lng', 'check_out_lat', 'check_out_lng',
    ];

    protected $casts = [
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
    ];

    protected $appends = ['duration_seconds'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function getDurationSecondsAttribute(): ?int
    {
        if (!$this->check_out_at) {
            return null; // still open — frontend computes live duration client-side
        }

        return $this->check_out_at->diffInSeconds($this->check_in_at);
    }
}
