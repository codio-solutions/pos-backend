<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\VisitController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth
|--------------------------------------------------------------------------
| Login is public (email/password in, token out). Everything else in
| this file requires that token, except the website webhook below.
*/

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Shifts — both roles can check themselves in/out and see their own
    // current status. Only admin sees the cross-employee working hours log.
    Route::post('/shifts/check-in', [ShiftController::class, 'checkIn']);
    Route::post('/shifts/check-out', [ShiftController::class, 'checkOut']);
    Route::get('/shifts/current', [ShiftController::class, 'current']);
    Route::get('/shifts/mine', [ShiftController::class, 'mine']);

    // Read-only product list — both roles need this: admin for POS,
    // employee for the visit form's product picker. Only the admin group
    // below can create/edit/delete products.
    Route::get('/products', [ProductController::class, 'index']);

    // Visits — employee submits from the field; admin monitors everyone's.
    Route::post('/visits', [VisitController::class, 'store']);
    Route::get('/visits/mine', [VisitController::class, 'mine']);

    // Admin-only: POS core + workforce oversight. An employee (field rep)
    // token hitting any of these gets a 403 from the role middleware.
    Route::middleware('role:admin')->group(function () {
        // Products (write)
        Route::post('/products', [ProductController::class, 'store']);
        Route::put('/products/{product}', [ProductController::class, 'update']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);

        // Purchasing
        Route::get('/purchases', [PurchaseController::class, 'index']);
        Route::post('/purchases', [PurchaseController::class, 'store']);
        Route::post('/purchase-returns', [PurchaseController::class, 'storeReturn']);

        // Selling (POS checkout) + sale returns
        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::post('/sale-returns', [OrderController::class, 'storeReturn']);

        // Reports
        Route::get('/reports/sales-and-stock', [ReportController::class, 'salesAndStock']);

        // Working hours — cross-employee shift log + manual correction
        Route::get('/shifts', [ShiftController::class, 'index']);
        Route::patch('/shifts/{shift}/close', [ShiftController::class, 'close']);

        // Employee accounts — create field rep logins, emailed automatically
        Route::get('/employees', [EmployeeController::class, 'index']);
        Route::post('/employees', [EmployeeController::class, 'store']);
        Route::post('/employees/{employee}/reset-password', [EmployeeController::class, 'resetPassword']);

        // Visits — admin monitoring view (Visits / Submitted Data pages)
        Route::get('/visits', [VisitController::class, 'index']);
    });
});

// Website → POS integration. Authenticated via HMAC signature (see
// OrderController::verifyWebhookSignature), not session auth — the
// website server calls this directly, no logged-in user involved.
Route::post('/orders/webhook', [OrderController::class, 'websiteWebhook']);
