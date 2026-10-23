<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        // one token per login call; if you want to invalidate old sessions on
        // login, call $user->tokens()->delete() before creating the new one
        $token = $user->createToken('dashboard')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email', 'token' => 'required|string',
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);
        $status = DB::transaction(function () use ($data) {
            User::where('email', $data['email'])->lockForUpdate()->first();

            return \Illuminate\Support\Facades\Password::reset($data, function ($user, $password) {
                abort_unless($user->is_active, 403, 'This account is suspended.');
                DB::transaction(function () use ($user, $password) {
                    $user->update(['password' => Hash::make($password)]);
                    $user->tokens()->delete();
                    Audit::record('password.set', $user);
                });
            });
        });
        abort_unless($status === \Illuminate\Support\Facades\Password::PASSWORD_RESET, 422, __($status));

        return response()->json(['message' => 'Password saved. You can sign in now.']);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ]);
    }
}
