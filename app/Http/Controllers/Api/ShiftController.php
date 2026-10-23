<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\User;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        return DB::transaction(function () use ($request) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $existing = Shift::where('employee_id', $request->user()->id)
                ->whereNull('check_out_at')
                ->exists();

            abort_if($existing, 422, 'You already have an open shift — check out first.');

            $data = $request->validate([
                'lat' => 'nullable|numeric|between:-90,90',
                'lng' => 'nullable|numeric|between:-180,180',
            ]);

            $shift = Shift::create([
                'employee_id' => $request->user()->id,
                'check_in_at' => now(),
                'check_in_lat' => $data['lat'] ?? null,
                'check_in_lng' => $data['lng'] ?? null,
            ]);

            return response()->json($shift, 201);
        });
    }

    public function checkOut(Request $request)
    {
        return DB::transaction(function () use ($request) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $shift = Shift::where('employee_id', $request->user()->id)
                ->whereNull('check_out_at')
                ->latest('check_in_at')
                ->first();

            abort_unless($shift, 422, 'No open shift to check out of.');

            $data = $request->validate([
                'lat' => 'nullable|numeric|between:-90,90',
                'lng' => 'nullable|numeric|between:-180,180',
            ]);

            $shift->update([
                'check_out_at' => now(),
                'check_out_lat' => $data['lat'] ?? null,
                'check_out_lng' => $data['lng'] ?? null,
            ]);

            return response()->json($shift);
        });
    }

    /**
     * Own shift history + summary stats — backs the employee portal's
     * "My Hours" page and the "Today's history" panel on check-in/out.
     */
    public function mine(Request $request)
    {
        $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $query = Shift::where('employee_id', $request->user()->id)
            ->when($request->from, fn ($q, $d) => $q->whereDate('check_in_at', '>=', $d))
            ->when($request->to, fn ($q, $d) => $q->whereDate('check_in_at', '<=', $d));
        $shifts = (clone $query)->latest('check_in_at')->paginate(25);
        $totalSeconds = 0;
        $completedCount = 0;
        foreach ((clone $query)->whereNotNull('check_out_at')->cursor() as $completedShift) {
            $totalSeconds += $completedShift->duration_seconds;
            $completedCount++;
        }

        return response()->json([
            'shifts' => $shifts->items(),
            'pagination' => ['current_page' => $shifts->currentPage(), 'last_page' => $shifts->lastPage(), 'total' => $shifts->total()],
            'open_shift' => Shift::where('employee_id', $request->user()->id)->whereNull('check_out_at')->first(),
            'period' => ['from' => $request->from, 'to' => $request->to],
            'stats' => [
                'completed_count' => $completedCount,
                'total_seconds' => $totalSeconds,
                'average_seconds' => $completedCount > 0
                    ? (int) round($totalSeconds / $completedCount)
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
            'check_out_at' => 'required|date',
            'reason' => 'required|string|max:255',
        ]);

        DB::transaction(function () use ($shift, $data) {
            $locked = Shift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->check_out_at, 422, 'This shift is already closed.');
            $end = Carbon::parse($data['check_out_at']);
            abort_unless($end->greaterThan($locked->check_in_at) && $end->lessThanOrEqualTo(now()), 422, 'Checkout must be after check-in and not in the future.');
            $locked->update(['check_out_at' => $end]);
            Audit::record('shift.closed', $locked, $data);
        });
        $shift->refresh();

        return response()->json($shift);
    }
}
