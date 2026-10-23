<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IdempotentRequest
{
    public function handle(Request $request, Closure $next)
    {
        $paths = ['api/orders', 'api/purchases', 'api/purchase-returns', 'api/sale-returns', 'api/visits'];
        if (! $request->isMethod('POST') || ! in_array($request->path(), $paths, true)) {
            return $next($request);
        }
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/^[a-zA-Z0-9_-]{16,100}$/', $key), 422, 'A valid Idempotency-Key header is required.');
        $input = $request->input();
        ksort($input);
        $files = [];
        foreach ($request->allFiles() as $name => $file) {
            abort_if(is_array($file), 422, 'Unexpected file array.');
            $files[$name] = hash_file('sha256', $file->getRealPath());
        }
        ksort($files);
        $fingerprint = hash('sha256', $request->path().json_encode([$input, $files]));

        return DB::transaction(function () use ($request, $next, $key, $fingerprint) {
            // Serializes receipt creation and retries for this account, including on MySQL.
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $query = DB::table('request_receipts')->where('user_id', $request->user()->id)->where('request_key', $key);
            if ($receipt = $query->first()) {
                abort_unless(hash_equals($receipt->fingerprint, $fingerprint), 409, 'This submission key was already used for different data.');

                return response($receipt->response, $receipt->status)->header('Content-Type', 'application/json');
            }
            $response = $next($request);
            if ($response->isSuccessful()) {
                DB::table('request_receipts')->insert([
                    'user_id' => $request->user()->id, 'request_key' => $key,
                    'fingerprint' => $fingerprint, 'status' => $response->getStatusCode(),
                    'response' => $response->getContent(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $response;
        });
    }
}
