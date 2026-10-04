<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Visit extends Model
{
    protected $fillable = [
        'employee_id', 'doctor_name', 'clinic_name', 'doctor_phone',
        'doctor_email', 'clinic_address', 'notes', 'lat', 'lng',
        'doctor_photo_path', 'building_photo_path',
    ];

    protected $appends = ['doctor_photo_url', 'building_photo_url'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(VisitOrderItem::class);
    }

    public function getDoctorPhotoUrlAttribute(): ?string
    {
        return $this->doctor_photo_path ? Storage::disk('public')->url($this->doctor_photo_path) : null;
    }

    public function getBuildingPhotoUrlAttribute(): ?string
    {
        return $this->building_photo_path ? Storage::disk('public')->url($this->building_photo_path) : null;
    }
}
