<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MeController;
use Illuminate\Support\Facades\Route;

/*
| Public endpoints (SEC-1: everything else requires a Sanctum token)
*/
Route::get('/health', HealthController::class)->name('health');
Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');
Route::middleware('throttle:guest')->group(function () {
    Route::post('/auth/forgot-password', [PasswordResetController::class, 'forgot'])->name('password.email');
    Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])->name('password.update');
});

Route::middleware(['auth:sanctum', 'activity', 'throttle:api'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);

    Route::get('/me', [MeController::class, 'show']);
    Route::patch('/me', [MeController::class, 'update']);
    Route::post('/me/avatar', [MeController::class, 'avatar'])->middleware('throttle:uploads');

    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::post('/users', [AdminUserController::class, 'store']);
        Route::post('/users/import', [AdminUserController::class, 'import'])->middleware('throttle:uploads');
        Route::patch('/users/{user}', [AdminUserController::class, 'update']);
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
    });
});
