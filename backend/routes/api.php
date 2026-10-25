<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CourseController as AdminCourseController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\SubmissionController;
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
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login-ip')->name('auth.login');
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

    // Files
    Route::post('/attachments', [AttachmentController::class, 'store'])->middleware('throttle:uploads');
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show']);

    // Courses (LMS)
    Route::get('/courses', [CourseController::class, 'index']);
    Route::get('/courses/{course}', [CourseController::class, 'show']);
    Route::get('/courses/{course}/people', [CourseController::class, 'people']);

    Route::get('/courses/{course}/materials', [MaterialController::class, 'index']);
    Route::post('/courses/{course}/materials', [MaterialController::class, 'store']);
    Route::patch('/materials/{material}', [MaterialController::class, 'update']);
    Route::delete('/materials/{material}', [MaterialController::class, 'destroy']);

    Route::get('/courses/{course}/announcements', [AnnouncementController::class, 'index']);
    Route::post('/courses/{course}/announcements', [AnnouncementController::class, 'store']);
    Route::patch('/announcements/{announcement}', [AnnouncementController::class, 'update']);
    Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy']);

    Route::get('/courses/{course}/assignments', [AssignmentController::class, 'index']);
    Route::post('/courses/{course}/assignments', [AssignmentController::class, 'store']);
    Route::get('/assignments/{assignment}', [AssignmentController::class, 'show']);
    Route::patch('/assignments/{assignment}', [AssignmentController::class, 'update']);
    Route::delete('/assignments/{assignment}', [AssignmentController::class, 'destroy']);

    Route::get('/assignments/{assignment}/submissions', [SubmissionController::class, 'index']);
    Route::post('/assignments/{assignment}/submissions', [SubmissionController::class, 'store']);
    Route::get('/assignments/{assignment}/my-submission', [SubmissionController::class, 'mine']);
    Route::patch('/submissions/{submission}/grade', [SubmissionController::class, 'grade']);

    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::post('/users', [AdminUserController::class, 'store']);
        Route::post('/users/import', [AdminUserController::class, 'import'])->middleware('throttle:uploads');
        Route::patch('/users/{user}', [AdminUserController::class, 'update']);
        Route::get('/courses', [AdminCourseController::class, 'index']);
        Route::post('/courses', [AdminCourseController::class, 'store']);
        Route::patch('/courses/{course}', [AdminCourseController::class, 'update']);
        Route::delete('/courses/{course}', [AdminCourseController::class, 'destroy']);
        Route::post('/courses/{course}/enrolments', [AdminCourseController::class, 'enrol']);
        Route::delete('/courses/{course}/enrolments', [AdminCourseController::class, 'unenrol']);
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
    });
});
