<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\EmployeeWelcomeMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EmployeeController extends Controller
{
    public function index()
    {
        $employees = User::where('role', 'employee')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'created_at']);

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

    /**
     * Admin triggers this when an employee forgot their password. Generates
     * a fresh one, overwrites the stored hash, and emails it the same way
     * as initial account creation — with the same manual-fallback if mail
     * delivery fails.
     */
    public function resetPassword(Request $request, User $employee)
    {
        abort_unless($employee->role === 'employee', 404);

        $result = $this->issueNewPassword($employee, isReset: true);

        return response()->json([
            'employee' => $employee->only(['id', 'name', 'email', 'phone']),
            ...$result,
        ]);
    }

    /**
     * Shared by store() and resetPassword(): generates a new random
     * password, saves it (hashed) on the user, and emails it — falling
     * back to returning it in the response if the email fails to send.
     */
    private function issueNewPassword(User $employee, bool $isReset): array
    {
        $plainPassword = Str::password(12);

        $employee->update(['password' => Hash::make($plainPassword)]);

        $emailSent = true;
        try {
            Mail::to($employee->email)->send(new EmployeeWelcomeMail($employee, $plainPassword, $isReset));
        } catch (\Throwable $e) {
            // Don't fail the request just because mail delivery failed
            // (e.g. SMTP not configured yet) — surface it to the admin
            // instead so they can share the password manually.
            $emailSent = false;
            report($e);
        }

        return [
            'email_sent' => $emailSent,
            // Only returned so the admin can share it manually if the email
            // failed to send — the frontend should only display this when
            // email_sent is false.
            'temporary_password' => $emailSent ? null : $plainPassword,
        ];
    }
}
