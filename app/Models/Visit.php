<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Visit extends Model
{
    protected $fillable = [
        'employee_id', 'doctor_name', 'clinic_name', 'doctor_phone',
        'doctor_email', 'clinic_address', 'notes', 'lat', 'lng',
        'travel_expense', 'other_expense', 'doctor_photo_path', 'building_photo_path', 'order_id',
    ];

    protected $appends = ['doctor_photo_url', 'building_photo_url'];

    protected $casts = [
        'travel_expense' => 'decimal:2',
        'other_expense' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(VisitOrderItem::class);
    }

    public function getDoctorPhotoUrlAttribute(): ?string
    {
        return $this->doctor_photo_path ? url('/api/visits/'.$this->id.'/photos/doctor') : null;
    }

    public function getBuildingPhotoUrlAttribute(): ?string
    {
        return $this->building_photo_path ? url('/api/visits/'.$this->id.'/photos/building') : null;
    }
}
