<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    /**
     * Current open shift (if any) for the logged-in employee — drives the
     * check-in/out button state and the running timer on the frontend.
     */
    public function current(Request $request)
    {
        $shift = Shift::where('employee_id', $request->user()->id)
            ->whereNull('check_out_at')
            ->latest('check_in_at')
            ->first();

        return response()->json($shift)->withHeaders([
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'CDN-Cache-Control' => 'no-store',
            'Cloudflare-CDN-Cache-Control' => 'no-store',
        ]);
    }

    public function checkIn(Request $request)
    {
        $existing = Shift::where('employee_id', $request->user()->id)
            ->whereNull('check_out_at')
            ->exists();

        abort_if($existing, 422, 'You already have an open shift — check out first.');

        $data = $request->validate([
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
        ]);

        $shift = Shift::create([
            'employee_id' => $request->user()->id,
            'check_in_at' => now(),
            'check_in_lat' => $data['lat'] ?? null,
            'check_in_lng' => $data['lng'] ?? null,
        ]);

        return response()->json($shift, 201);
    }

    public function checkOut(Request $request)
    {
        $shift = Shift::where('employee_id', $request->user()->id)
            ->whereNull('check_out_at')
            ->latest('check_in_at')
            ->first();

        abort_unless($shift, 422, 'No open shift to check out of.');

        $data = $request->validate([
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
        ]);

        $shift->update([
            'check_out_at' => now(),
            'check_out_lat' => $data['lat'] ?? null,
            'check_out_lng' => $data['lng'] ?? null,
        ]);

        return response()->json($shift);
    }

    /**
     * Own shift history + summary stats — backs the employee portal's
     * "My Hours" page and the "Today's history" panel on check-in/out.
     */
    public function mine(Request $request)
    {
        $shifts = Shift::where('employee_id', $request->user()->id)
            ->latest('check_in_at')
            ->limit(50)
            ->get();

        $completed = $shifts->whereNotNull('check_out_at');
        $totalSeconds = $completed->sum('duration_seconds');

        return response()->json([
            'shifts' => $shifts,
            'stats' => [
                'completed_count' => $completed->count(),
                'total_seconds' => $totalSeconds,
                'average_seconds' => $completed->count() > 0
                    ? (int) round($totalSeconds / $completed->count())
                    : 0,
            ],
        ])->withHeaders([
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'CDN-Cache-Control' => 'no-store',
            'Cloudflare-CDN-Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Admin-only: working hours log across all employees, filterable by
     * employee/date. Enforced by role:admin middleware on the route.
     */
    public function index(Request $request)
    {
        $shifts = Shift::with('employee:id,name')
            ->when($request->employee_id, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($request->from, fn ($q, $d) => $q->whereDate('check_in_at', '>=', $d))
            ->when($request->to, fn ($q, $d) => $q->whereDate('check_in_at', '<=', $d))
            ->latest('check_in_at')
            ->paginate(50);

        return response()->json($shifts);
    }

    /**
     * Admin-only: manually close a shift an employee forgot to check out of.
     */
    public function close(Request $request, Shift $shift)
    {
        $data = $request->validate([
            'check_out_at' => 'required|date|after:check_in_at',
        ]);

        $shift->update(['check_out_at' => $data['check_out_at']]);

        return response()->json($shift);
    }
}
