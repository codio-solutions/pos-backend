<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Audit
{
    public static function record(string $action, Model $subject, array $details = []): void
    {
        DB::table('audit_events')->insert([
            'user_id' => auth()->id(), 'action' => $action,
            'subject_type' => get_class($subject), 'subject_id' => $subject->getKey(),
            'details' => json_encode($details), 'created_at' => now(),
        ]);
    }
}
