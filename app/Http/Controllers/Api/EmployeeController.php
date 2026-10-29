<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\EmployeeWelcomeMail;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $employees = User::where('role', 'employee')
            ->when($request->search, fn ($query, $search) => $query->where(function ($nested) use ($search) {
                $nested->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->orderBy('name')
            ->paginate(25, ['id', 'name', 'email', 'phone', 'is_active', 'created_at']);

        return response()->json($employees);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:50',
        ]);

        $employee = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make(Str::password(12)), // placeholder — replaced below
            'role' => 'employee',
            'email_verified_at' => now(),
        ]);

        $result = $this->issueNewPassword($employee, isReset: false);

        return response()->json([
            'employee' => $employee->only(['id', 'name', 'email', 'phone']),
            ...$result,
        ], 201);
    }

    public function resetPassword(Request $request, User $employee)
    {
        abort_unless($employee->role === 'employee', 404);

        $result = $this->issueNewPassword($employee, isReset: true);

        return response()->json([
            'employee' => $employee->only(['id', 'name', 'email', 'phone']),
            ...$result,
        ]);
    }

    public function update(Request $request, User $employee)
    {
        abort_unless($employee->role === 'employee', 404);
        $data = $request->validate(['is_active' => 'required|boolean']);
        DB::transaction(function () use ($employee, $data) {
            $employee->update($data);
            if (! $employee->is_active) {
                $employee->tokens()->delete();
            }
            Audit::record('employee.status', $employee, $data);
        });

        return response()->json($employee);
    }

    private function issueNewPassword(User $employee, bool $isReset): array
    {
        // Immediately invalidate prior sessions and passwords; the emailed link expires in 60 minutes.
        $token = DB::transaction(function () use ($employee, $isReset) {
            $employee = User::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $employee->update(['password' => Hash::make(Str::random(64))]);
            $employee->tokens()->delete();
            $token = Password::createToken($employee);
            Audit::record($isReset ? 'employee.reset' : 'employee.created', $employee);

            return $token;
        });
        $url = rtrim(config('services.frontend.url'), '/').'/reset-password/#'.http_build_query([
            'token' => $token, 'email' => $employee->email,
        ]);
        $emailQueued = true;
        try {
            Mail::to($employee->email)->queue(
                (new EmployeeWelcomeMail($employee, $url, $isReset))->onConnection('database')
            );
        } catch (\Throwable $e) {
            $emailQueued = false;
            report($e);
        }

        return ['email_queued' => $emailQueued, 'setup_url' => $emailQueued ? null : $url];
    }
}
